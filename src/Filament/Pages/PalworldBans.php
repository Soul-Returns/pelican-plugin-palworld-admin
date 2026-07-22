<?php

namespace Soul\PalworldAdmin\Filament\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Soul\PalworldAdmin\Api\PalworldApiException;
use Soul\PalworldAdmin\Models\PalworldBan;
use Soul\PalworldAdmin\PalworldService;

class PalworldBans extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Ban;

    protected static ?int $navigationSort = 32;

    /** Reached via the "Banned players" button on the players page, not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'palworld-admin::pages.bans';

    public ?string $loadError = null;

    /**
     * Unbans just issued from this page. The game rewrites banlist.txt
     * asynchronously, so a re-read right after the unban still contains the
     * entry - these are filtered out until the file catches up.
     *
     * @var string[]
     */
    public array $justUnbanned = [];

    public static function getNavigationLabel(): string
    {
        return 'Palworld Bans';
    }

    public function getTitle(): string
    {
        return 'Palworld Bans';
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && PalworldService::isPalworldServer($server)
            && (user()?->can(SubuserPermission::ControlConsole, $server) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->loadBans())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->placeholder('Unknown (banned outside the panel)'),
                TextColumn::make('account_name')
                    ->label('Account')
                    ->placeholder('—'),
                TextColumn::make('user_id')
                    ->label('User ID')
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('player_uid')
                    ->label('Player UID')
                    ->copyable()
                    ->fontFamily('mono')
                    ->limit(12)
                    ->tooltip(fn (array $record) => $record['player_uid'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('level')
                    ->label('Level')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('ip')
                    ->label('Last IP')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')
                    ->label('Reason')
                    ->limit(40)
                    ->tooltip(fn (array $record) => $record['message'])
                    ->placeholder('—'),
                TextColumn::make('banned_by')
                    ->label('Banned by')
                    ->placeholder('—'),
                TextColumn::make('banned_at')
                    ->label('Banned at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('unban')
                    ->label('Unban')
                    ->icon(TablerIcon::UserOff)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => 'Unban ' . ($record['name'] ?: $record['user_id']))
                    ->action(function (array $record) {
                        $server = $this->getServer();

                        abort_unless(user()?->can(SubuserPermission::ControlConsole, $server), 403);

                        try {
                            PalworldService::clientFor($server)->unban($record['user_id']);
                        } catch (PalworldApiException $e) {
                            Notification::make()->title('Failed to unban')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        PalworldBan::where('server_id', $server->id)->where('user_id', $record['user_id'])->delete();

                        $this->justUnbanned[] = $record['user_id'];
                        $this->flushCachedTableRecords();

                        Notification::make()->title(($record['name'] ?: $record['user_id']) . ' was unbanned.')->success()->send();
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->headerActions([
                Action::make('back_to_players')
                    ->label('Back to players')
                    ->icon(TablerIcon::Users)
                    ->link()
                    ->url(fn () => PalworldPlayers::getUrl()),
                Action::make('ban_by_id')
                    ->label('Ban by ID')
                    ->icon(TablerIcon::Ban)
                    ->color('danger')
                    ->schema([
                        TextInput::make('userid')
                            ->label('User ID (e.g. steam_7656119…)')
                            ->required(),
                        Textarea::make('message')
                            ->label('Message shown to the player (if online)')
                            ->default('You have been banned.')
                            ->rows(2),
                    ])
                    ->action(function (array $data) {
                        $server = $this->getServer();

                        abort_unless(user()?->can(SubuserPermission::ControlConsole, $server), 403);

                        $userId = trim($data['userid']);

                        try {
                            PalworldService::clientFor($server)->ban($userId, $data['message'] ?: 'You have been banned.');
                        } catch (PalworldApiException $e) {
                            Notification::make()->title('Failed to ban')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        PalworldBan::updateOrCreate(
                            ['server_id' => $server->id, 'user_id' => $userId],
                            ['message' => $data['message'] ?: 'You have been banned.', 'banned_by' => user()?->username],
                        );

                        $this->justUnbanned = array_values(array_diff($this->justUnbanned, [$userId]));
                        $this->flushCachedTableRecords();

                        Notification::make()->title($userId . ' was banned.')->success()->send();
                    }),
            ])
            ->poll('15s')
            ->paginated(false)
            ->emptyStateIcon(TablerIcon::Ban)
            ->emptyStateHeading(fn () => $this->loadError ? 'Could not load ban list' : 'No banned players')
            ->emptyStateDescription(fn () => $this->loadError ?? 'Players banned on this server show up here.');
    }

    /**
     * banlist.txt is the source of truth; the plugin's ban ledger enriches
     * entries with everything known at ban time.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function loadBans(): array
    {
        $this->loadError = null;
        $server = $this->getServer();

        try {
            $banList = PalworldService::readBanList($server);
        } catch (\Exception $e) {
            $this->loadError = $e->getMessage();

            return [];
        }

        $banList = array_diff_key($banList, array_flip($this->justUnbanned));

        $ledger = PalworldBan::where('server_id', $server->id)
            ->whereIn('user_id', array_keys($banList))
            ->get()
            ->keyBy('user_id');

        $rows = [];
        foreach ($banList as $userId => $playerUid) {
            $entry = $ledger->get($userId);

            $rows[$userId] = [
                'user_id' => $userId,
                'player_uid' => $playerUid ?: ($entry?->player_id ?? ''),
                'name' => $entry?->name,
                'account_name' => $entry?->account_name,
                'level' => $entry?->level,
                'ip' => $entry?->ip,
                'message' => $entry?->message,
                'banned_by' => $entry?->banned_by,
                'banned_at' => $entry?->created_at,
            ];
        }

        return $rows;
    }

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
