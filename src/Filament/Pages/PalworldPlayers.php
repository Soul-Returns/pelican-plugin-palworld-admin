<?php

namespace Soul\PalworldAdmin\Filament\Pages;

use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Nodes\NodeJWTService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Soul\PalworldAdmin\Api\PalworldApiException;
use Soul\PalworldAdmin\Api\Player;
use Soul\PalworldAdmin\Models\PalworldBan;
use Soul\PalworldAdmin\PalworldService;

class PalworldPlayers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Users;

    protected static ?int $navigationSort = 30;

    protected string $view = 'palworld-admin::pages.players';

    public ?string $apiError = null;

    /** Friendly reason the API is expectedly down ("Server not running"), or null. */
    public ?string $offlineLabel = null;

    /** In-flight paltools request: {id, state: listing|filtering, polls}. */
    public ?array $palExport = null;

    /** Roster from the watcher's players.json (uid/name/pals/group_id). */
    public ?array $palPlayers = null;

    protected function paltoolsWrite(array $request): void
    {
        (new DaemonFileRepository())->setServer($this->getServer())
            ->putContent('.paltools/request.json', json_encode($request));
    }

    /**
     * Polled by the view every 3s while a paltools request is in flight.
     * The watcher (palworld yolk image) answers via .paltools/status.json.
     */
    public function checkPalExport(): void
    {
        if (!$this->palExport) {
            return;
        }

        if (++$this->palExport['polls'] > 60) {
            $this->palExport = null;
            Notification::make()->title('Pal export timed out')
                ->body('No answer from the paltools watcher - is the server running on the Palworld yolk image (egg v9+)?')
                ->danger()->send();

            return;
        }

        $files = (new DaemonFileRepository())->setServer($this->getServer());

        try {
            $status = json_decode($files->getContent('.paltools/status.json', 65536), true);
        } catch (\Exception) {
            return; // not answered yet
        }

        if (!is_array($status) || ($status['id'] ?? null) !== $this->palExport['id'] || ($status['state'] ?? null) === 'running') {
            return;
        }

        $state = $this->palExport['state'];
        $this->palExport = null;

        if ($status['state'] === 'error') {
            Notification::make()->title('Pal export failed')->body($status['detail'] ?? 'unknown error')->danger()->send();

            return;
        }

        if ($state === 'listing') {
            try {
                $roster = json_decode($files->getContent('.paltools/players.json', 1048576), true);
            } catch (\Exception $e) {
                Notification::make()->title('Could not read player list')->body($e->getMessage())->danger()->send();

                return;
            }

            $this->palPlayers = $roster['players'] ?? [];

            Notification::make()->title('Player list ready')
                ->body('Pick the players to export in the dialog.')
                ->success()->send();

            // table header actions mount through the table, not the page
            $this->mountTableAction('export_pals_choose');

            return;
        }

        // filtering done -> hand the browser a signed download of the result
        $server = $this->getServer();
        $token = app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setScopes(NodeJwtScope::FileDownload)
            ->setUser(user())
            ->setClaims(['file_path' => $status['output'] ?? '.paltools/export/Level.filtered.sav', 'server_uuid' => $server->uuid])
            ->handle($server->node, user()?->id . $server->uuid);

        Activity::event('server:file.download')->property('file', 'paltools export')->log();

        redirect()->away($server->node->getConnectionAddress() . '/download/file?token=' . $token->toString());
    }

    public static function getNavigationLabel(): string
    {
        return 'Palworld Players';
    }

    public function getTitle(): string
    {
        return 'Palworld Players';
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && PalworldService::isPalworldServer($server)
            && (user()?->can(SubuserPermission::ControlConsole, $server) ?? false);
    }

    public function getSubheading(): ?string
    {
        try {
            $metrics = PalworldService::clientFor($this->getServer())->metrics();
        } catch (\Exception) {
            return null;
        }

        $uptime = (int) ($metrics['uptime'] ?? 0);

        return sprintf(
            '%d/%d players online · %d FPS · day %d · up %s',
            $metrics['currentplayernum'] ?? 0,
            $metrics['maxplayernum'] ?? 0,
            $metrics['serverfps'] ?? 0,
            $metrics['days'] ?? 0,
            $uptime >= 3600 ? intdiv($uptime, 3600) . 'h ' . intdiv($uptime % 3600, 60) . 'm' : intdiv($uptime, 60) . 'm',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => new \Illuminate\Support\HtmlString(
                view('palworld-admin::components.live-indicator', [
                    'error' => $this->apiError,
                    // computed directly (not just from loadPlayers) because the
                    // heading renders before the records resolve - otherwise a
                    // fresh page load shows green for one poll cycle
                    'offline' => $this->offlineLabel ?? PalworldService::offlineLabel($this->getServer()),
                ])->render()
            ))
            ->records(fn (): array => $this->loadPlayers())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(false)
                    ->weight('bold'),
                TextColumn::make('accountName')
                    ->label('Account'),
                TextColumn::make('userId')
                    ->label('User ID')
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('level')
                    ->label('Level')
                    ->alignCenter(),
                TextColumn::make('ping')
                    ->label('Ping')
                    ->formatStateUsing(fn ($state) => round((float) $state) . ' ms')
                    ->alignCenter(),
                TextColumn::make('location_x')
                    ->label('Location')
                    ->formatStateUsing(fn ($state, array $record) => round((float) $record['location_x']) . ', ' . round((float) $record['location_y'])),
                TextColumn::make('building_count')
                    ->label('Buildings')
                    ->alignCenter(),
            ])
            ->recordActions([
                Action::make('kick')
                    ->label('Kick')
                    ->icon(TablerIcon::UserX)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => 'Kick ' . $record['name'])
                    ->schema([
                        Textarea::make('message')
                            ->label('Message shown to the player')
                            ->default('You have been kicked.')
                            ->rows(2),
                    ])
                    ->action(fn (array $data, array $record) => $this->run(
                        'kick',
                        fn () => $this->client()->kick($record['userId'], $data['message'] ?: 'You have been kicked.'),
                        $record['name'] . ' was kicked.',
                    )),
                Action::make('ban')
                    ->label('Ban')
                    ->icon(TablerIcon::Ban)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => 'Ban ' . $record['name'])
                    ->modalDescription('Bans the player by their user ID. Use "Unban" in the header to revert.')
                    ->schema([
                        Textarea::make('message')
                            ->label('Message shown to the player')
                            ->default('You have been banned.')
                            ->rows(2),
                    ])
                    ->action(fn (array $data, array $record) => $this->run(
                        'ban',
                        function () use ($data, $record) {
                            $message = $data['message'] ?: 'You have been banned.';

                            $this->client()->ban($record['userId'], $message);

                            try {
                                PalworldBan::updateOrCreate(
                                    ['server_id' => $this->getServer()->id, 'user_id' => $record['userId']],
                                    [
                                        'name' => $record['name'],
                                        'account_name' => $record['accountName'],
                                        'player_id' => $record['playerId'],
                                        'ip' => $record['ip'],
                                        'level' => $record['level'],
                                        'message' => $message,
                                        'banned_by' => user()?->username,
                                    ],
                                );
                            } catch (\Exception $e) {
                                report($e); // ban succeeded; a ledger failure must not fail the action
                            }
                        },
                        $record['name'] . ' was banned.',
                    )),
            ])
            ->headerActions([
                Action::make('banned_players')
                    ->label('Banned players')
                    ->icon(TablerIcon::Ban)
                    ->color('danger')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->url(fn () => PalworldBans::getUrl()),
                Action::make('announce')
                    ->label('Announce')
                    ->icon(TablerIcon::Speakerphone)
                    ->color('info')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->schema([
                        Textarea::make('message')
                            ->label('Message')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(fn (array $data) => $this->run(
                        'announce',
                        fn () => $this->client()->announce($data['message']),
                        'Announcement sent.',
                    )),
                Action::make('save_world')
                    ->label('Save world')
                    ->icon(TablerIcon::DeviceFloppy)
                    ->color('success')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->action(fn () => $this->run(
                        'save',
                        fn () => $this->client()->save(),
                        'World saved.',
                    )),
                Action::make('export_pals')
                    ->label('Export Pals')
                    ->icon(TablerIcon::Users)
                    ->color('info')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->visible(fn () => $this->palPlayers === null
                        && (user()?->can(SubuserPermission::FileReadContent, $this->getServer()) ?? false)
                        && PalworldService::offlineLabel($this->getServer()) === null)
                    ->disabled(fn () => $this->palExport !== null)
                    ->action(function () {
                        $id = uniqid();
                        $this->paltoolsWrite(['id' => $id, 'action' => 'list']);
                        $this->palExport = ['id' => $id, 'state' => 'listing', 'polls' => 0];
                    }),
                Action::make('export_pals_choose')
                    ->label('Export Pals')
                    ->icon(TablerIcon::Users)
                    ->color('info')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->visible(fn () => $this->palPlayers !== null
                        && (user()?->can(SubuserPermission::FileReadContent, $this->getServer()) ?? false))
                    ->disabled(fn () => $this->palExport !== null)
                    ->modalHeading('Export selected players\' Pals')
                    ->modalDescription('Produces a filtered Level.sav containing only the selected players\' Pals - e.g. for palbreed.com ("Choose Level.sav instead"). Filtering runs on the game node; the download starts automatically when ready.')
                    ->schema([
                        CheckboxList::make('players')
                            ->label('Players')
                            ->required()
                            ->options(collect($this->palPlayers ?? [])->mapWithKeys(
                                fn ($p) => [$p['uid'] => sprintf('%s (%d pals)', $p['name'], $p['pals'])]
                            )->all())
                            ->columns(2),
                        Toggle::make('whole_guild')
                            ->label('Whole guild(s) of the selected players - includes shared base pals'),
                        Toggle::make('save_first')
                            ->label('Save world first (freshest data)')
                            ->default(true),
                    ])
                    ->modalSubmitActionLabel('Export')
                    ->action(function (array $data) {
                        $id = uniqid();
                        $key = ($data['whole_guild'] ?? false) ? 'guilds_of' : 'players';
                        $this->paltoolsWrite([
                            'id' => $id, 'action' => 'filter',
                            $key => array_values($data['players'] ?? []),
                            'save_first' => (bool) ($data['save_first'] ?? true),
                        ]);
                        $this->palExport = ['id' => $id, 'state' => 'filtering', 'polls' => 0];
                    }),
                Action::make('export_save')
                    ->label('Export save')
                    ->icon(TablerIcon::FileDownload)
                    ->color('gray')
                    ->button()
                    ->outlined()
                    ->size(Size::Medium)
                    ->visible(fn () => user()?->can(SubuserPermission::FileReadContent, $this->getServer()) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Export world save (Level.sav)')
                    ->modalDescription('Downloads this world\'s Level.sav from the node - usable e.g. with '
                        . 'palbreed.com/breeding-path ("Choose Level.sav instead") to plan breeding routes from '
                        . 'your actual Pals; that site reads the file locally in your browser, nothing is uploaded. '
                        . 'The file is only as fresh as the last world save: press "Save world" first and wait a '
                        . 'few seconds if you need current data.')
                    ->modalSubmitActionLabel('Download')
                    ->action(function (NodeJWTService $jwtService) {
                        $server = $this->getServer();

                        abort_unless(user()?->can(SubuserPermission::FileReadContent, $server), 403);

                        $path = PalworldService::worldSavePath($server);

                        if (!$path) {
                            Notification::make()
                                ->title('Could not locate Level.sav')
                                ->body('No world found under Pal/Saved/SaveGames/0 - has the server run at least once?')
                                ->danger()
                                ->send();

                            return;
                        }

                        $token = $jwtService
                            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
                            ->setScopes(NodeJwtScope::FileDownload)
                            ->setUser(user())
                            ->setClaims([
                                'file_path' => $path,
                                'server_uuid' => $server->uuid,
                            ])
                            ->handle($server->node, user()?->id . $server->uuid);

                        Activity::event('server:file.download')->property('file', $path)->log();

                        redirect()->away($server->node->getConnectionAddress() . '/download/file?token=' . $token->toString());
                    }),
            ])
            ->poll('15s')
            ->paginated(false)
            ->emptyStateIcon(fn () => $this->offlineLabel ? TablerIcon::PlayerStop : TablerIcon::UserOff)
            ->emptyStateHeading(fn () => $this->offlineLabel
                ?? ($this->apiError ? 'Palworld REST API unreachable' : 'No players online'))
            ->emptyStateDescription(fn () => $this->offlineLabel
                ? 'Start the server to see and manage players.'
                : ($this->apiError ?? 'Players appear here as soon as they connect.'));
    }

    /** @return array<string, array<string, mixed>> */
    protected function loadPlayers(): array
    {
        $this->apiError = null;
        $this->offlineLabel = null;

        try {
            $players = $this->client()->players();
        } catch (PalworldApiException $e) {
            $server = $this->getServer();

            if ($this->offlineLabel = PalworldService::offlineLabel($server)) {
                return [];
            }

            $hint = PalworldService::allocationMismatchHint($server);
            $this->apiError = $hint ? $hint . ' (' . $e->getMessage() . ')' : $e->getMessage();

            return [];
        }

        return collect($players)
            ->keyBy(fn (Player $player) => $player->playerId !== '' ? $player->playerId : $player->userId)
            ->map(fn (Player $player) => $player->toArray())
            ->all();
    }

    protected function run(string $what, \Closure $callback, string $successMessage): void
    {
        $server = $this->getServer();

        abort_unless(user()?->can(SubuserPermission::ControlConsole, $server), 403);

        try {
            $callback();
        } catch (PalworldApiException $e) {
            Notification::make()->title("Failed to {$what}")->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }

    protected function client(): \Soul\PalworldAdmin\Api\PalworldClient
    {
        return PalworldService::clientFor($this->getServer());
    }

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
