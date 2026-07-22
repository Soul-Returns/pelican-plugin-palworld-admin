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
use Filament\Tables\Table;
use Soul\PalworldAdmin\Api\PalworldApiException;
use Soul\PalworldAdmin\Api\Player;
use Soul\PalworldAdmin\PalworldService;

class PalworldPlayers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Users;

    protected static ?int $navigationSort = 30;

    protected string $view = 'palworld-admin::pages.players';

    public ?string $apiError = null;

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
                        fn () => $this->client()->ban($record['userId'], $data['message'] ?: 'You have been banned.'),
                        $record['name'] . ' was banned.',
                    )),
            ])
            ->headerActions([
                Action::make('announce')
                    ->label('Announce')
                    ->icon(TablerIcon::Speakerphone)
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
                Action::make('unban')
                    ->label('Unban')
                    ->icon(TablerIcon::UserOff)
                    ->schema([
                        TextInput::make('userid')
                            ->label('User ID (e.g. steam_7656119…)')
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->run(
                        'unban',
                        fn () => $this->client()->unban(trim($data['userid'])),
                        'Player unbanned.',
                    )),
                Action::make('save_world')
                    ->label('Save world')
                    ->icon(TablerIcon::DeviceFloppy)
                    ->color('success')
                    ->action(fn () => $this->run(
                        'save',
                        fn () => $this->client()->save(),
                        'World saved.',
                    )),
            ])
            ->poll('15s')
            ->paginated(false)
            ->emptyStateIcon(TablerIcon::UserOff)
            ->emptyStateHeading(fn () => $this->apiError ? 'Palworld REST API unreachable' : 'No players online')
            ->emptyStateDescription(fn () => $this->apiError
                ?? 'Players appear here as soon as they connect.');
    }

    /** @return array<string, array<string, mixed>> */
    protected function loadPlayers(): array
    {
        $this->apiError = null;

        try {
            $players = $this->client()->players();
        } catch (PalworldApiException $e) {
            $hint = PalworldService::allocationMismatchHint($this->getServer());
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
