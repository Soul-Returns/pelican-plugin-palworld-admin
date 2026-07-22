<?php

namespace Soul\PalworldAdmin\Filament\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Soul\PalworldAdmin\PalworldService;
use Soul\PalworldAdmin\Support\OptionSettings;

class PalworldWorldSettings extends ServerFormPage
{
    public const SETTINGS_PATH = 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini';

    /**
     * Keys the egg's config parser re-applies from panel Startup variables on
     * every boot — editing them here would be overwritten at the next start.
     */
    public const PANEL_MANAGED_KEYS = [
        'ServerName', 'ServerDescription', 'ServerPlayerMaxNum', 'ServerPassword',
        'AdminPassword', 'PublicIP', 'RCONEnabled', 'RCONPort', 'CrossplayPlatforms',
        'RESTAPIEnabled', 'RESTAPIPort',
    ];

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?int $navigationSort = 31;

    private ?DaemonFileRepository $fileRepository = null;

    public static function getNavigationLabel(): string
    {
        return 'Palworld Settings';
    }

    public function getTitle(): string
    {
        return 'Palworld World Settings';
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && PalworldService::isPalworldServer($server)
            && (user()?->can(SubuserPermission::FileReadContent, $server) ?? false);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->getRecord()), 403);
    }

    protected function fillForm(): void
    {
        try {
            $options = OptionSettings::parseIni($this->readIni())->values;
        } catch (\Exception $e) {
            Notification::make()
                ->title('Could not load ' . self::SETTINGS_PATH)
                ->body($e->getMessage())
                ->danger()
                ->send();

            $options = [];
        }

        $this->form->fill(['options' => $options]);
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make('World settings')
                ->description('Values from the OptionSettings tuple in ' . self::SETTINGS_PATH . '. '
                    . 'Managed by the panel and re-applied on every start (do not edit here): '
                    . implode(', ', self::PANEL_MANAGED_KEYS) . '.')
                ->columnSpanFull()
                ->schema([
                    KeyValue::make('options')
                        ->label('OptionSettings')
                        ->keyLabel('Setting')
                        ->valueLabel('Value')
                        ->addActionLabel('Add setting')
                        ->reorderable(false)
                        ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::FileUpdate, $server)),
                ])
                ->footerActions([
                    Action::make('save')
                        ->label('Save to file')
                        ->icon(TablerIcon::DeviceFloppy)
                        ->visible(fn (Server $server) => user()?->can(SubuserPermission::FileUpdate, $server) ?? false)
                        ->action(fn () => $this->save()),
                    Action::make('restart')
                        ->label('Restart server to apply')
                        ->icon(TablerIcon::Refresh)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Players will be disconnected. The world is saved automatically on shutdown.')
                        ->visible(fn (Server $server) => user()?->can(SubuserPermission::ControlRestart, $server) ?? false)
                        ->action(function (Server $server) {
                            app(DaemonServerRepository::class)->setServer($server)->power('restart');

                            Notification::make()->title('Server is restarting')->success()->send();
                        }),
                ])
                ->footerActionsAlignment(Alignment::Right),
        ]);
    }

    public function save(): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        $submitted = array_filter(
            (array) ($this->form->getState()['options'] ?? []),
            fn ($key) => trim((string) $key) !== '',
            ARRAY_FILTER_USE_KEY,
        );

        if ($submitted === []) {
            Notification::make()->title('Refusing to save an empty settings list')->danger()->send();

            return;
        }

        try {
            $ini = $this->readIni();

            $options = OptionSettings::parseIni($ini);
            $options->replaceValues($submitted);

            $this->fileRepository()->putContent(self::SETTINGS_PATH, $options->applyToIni($ini));
        } catch (\Exception $e) {
            Notification::make()->title('Failed to save settings')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Settings saved')
            ->body('Restart the server to apply the changes.')
            ->success()
            ->send();
    }

    private function readIni(): string
    {
        return $this->fileRepository()->getContent(self::SETTINGS_PATH, config('panel.files.max_edit_size'));
    }

    private function fileRepository(): DaemonFileRepository
    {
        return $this->fileRepository ??= (new DaemonFileRepository())->setServer($this->getRecord());
    }
}
