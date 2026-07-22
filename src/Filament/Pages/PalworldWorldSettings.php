<?php

namespace Soul\PalworldAdmin\Filament\Pages;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Soul\PalworldAdmin\PalworldService;
use Soul\PalworldAdmin\Support\OptionSettings;

class PalworldWorldSettings extends ServerFormPage
{
    public const SETTINGS_PATH = 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini';

    /**
     * Keys the egg's config parser re-applies on every boot, mapped to where
     * their value actually comes from. Shown read-only here; if one is missing
     * from the ini it is seeded on save (the parser can only update keys that
     * exist in the file).
     */
    public const PANEL_MANAGED_KEYS = [
        'ServerName' => 'SERVER_NAME',
        'ServerDescription' => 'SERVER_DESCRIPTION',
        'ServerPlayerMaxNum' => 'MAX_PLAYERS',
        'ServerPassword' => 'SERVER_PASSWORD',
        'AdminPassword' => 'ADMIN_PASSWORD',
        'PublicIP' => 'PUBLIC_IP',
        'PublicPort' => 'the primary allocation (Network tab)',
        'RCONEnabled' => 'RCON_ENABLE',
        'RCONPort' => 'RCON_PORT',
        'CrossplayPlatforms' => 'CROSSPLAY_PLATFORMS',
        'RESTAPIEnabled' => 'REST_API_ENABLED',
        'RESTAPIPort' => 'REST_API_PORT',
    ];

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?int $navigationSort = 31;

    protected string $view = 'palworld-admin::pages.world-settings';

    /** Power state this page is waiting for after Stop/Start: 'stopped' | 'running' | null. */
    public ?string $awaitingTarget = null;

    public int $awaitingPolls = 0;

    /** Game defaults from DefaultPalWorldSettings.ini, loaded lazily on first reset. */
    public ?array $defaults = null;

    /**
     * @return array<string, string> world-configuration defaults (managed keys excluded)
     *
     * @throws \Exception when the defaults file cannot be read
     */
    protected function defaults(): array
    {
        return $this->defaults ??= array_diff_key(
            OptionSettings::parseIni(
                $this->fileRepository()->getContent(config('palworld-admin.defaults_path'), config('panel.files.max_edit_size'))
            )->values,
            self::PANEL_MANAGED_KEYS,
        );
    }

    private ?DaemonFileRepository $fileRepository = null;

    private ?bool $serverStopped = null;

    /**
     * Polled (every 3s) by the view while awaiting a power change. Bypasses
     * the panel's 15s status cache so the form unlocks as soon as the server
     * actually reaches the target state.
     */
    public function checkPowerState(): void
    {
        if (!$this->awaitingTarget) {
            return;
        }

        cache()->forget("servers.{$this->getRecord()->uuid}.status");
        $this->serverStopped = null;

        $stopped = $this->serverStopped();

        if (($this->awaitingTarget === 'stopped') === $stopped) {
            $this->awaitingTarget = null;
            $this->awaitingPolls = 0;

            $this->fillForm();

            Notification::make()
                ->title($stopped ? 'Server stopped - editing unlocked' : 'Server started')
                ->success()
                ->send();

            return;
        }

        if (++$this->awaitingPolls > 40) {
            $this->awaitingTarget = null;
            $this->awaitingPolls = 0;

            Notification::make()
                ->title('Still waiting for the server')
                ->body('The power change is taking unusually long - check the Console page.')
                ->warning()
                ->send();
        }
    }

    /**
     * Palworld writes its in-memory settings back to PalWorldSettings.ini on
     * shutdown, silently overwriting anything saved while it runs - so the
     * file may only be edited while the server is fully stopped.
     */
    protected function serverStopped(): bool
    {
        return $this->serverStopped ??= (function (): bool {
            try {
                return in_array($this->getRecord()->retrieveStatus(), [
                    ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Dead,
                ], true);
            } catch (\Exception) {
                return false;
            }
        })();
    }

    protected function canEdit(): bool
    {
        return (user()?->can(SubuserPermission::FileUpdate, $this->getRecord()) ?? false)
            && $this->serverStopped();
    }

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
            $values = OptionSettings::parseIni($this->readIni())->values;
        } catch (\Exception $e) {
            Notification::make()
                ->title('Could not load ' . self::SETTINGS_PATH)
                ->body($e->getMessage())
                ->danger()
                ->send();

            $values = [];
        }

        $this->form->fill([
            'managed' => array_intersect_key($values, self::PANEL_MANAGED_KEYS),
            'options' => array_diff_key($values, self::PANEL_MANAGED_KEYS),
        ]);

        try {
            $this->defaults(); // preload so per-row reset buttons can dim when already at default
        } catch (\Exception) {
            // defaults file unreadable - reset buttons stay active, reset-all reports the error
        }
    }

    protected function isAtDefault(string $key): bool
    {
        return $this->defaults !== null
            && array_key_exists($key, $this->defaults)
            && trim((string) ($this->data['options'][$key] ?? '')) === $this->defaults[$key];
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make('World settings')
                ->description(fn () => $this->serverStopped()
                    ? 'Values from the OptionSettings tuple in ' . self::SETTINGS_PATH . '. '
                        . 'The locked fields are managed by the panel and re-applied on every server start '
                        . '- change them via the Startup tab (or the Network tab for the public port). '
                        . 'Everything else can be edited now; save, then start the server. '
                        . 'Locked settings missing from the file are added automatically on save.'
                    : 'Editing is locked while the server is running: Palworld writes its in-memory settings '
                        . 'back to ' . self::SETTINGS_PATH . ' on shutdown, which would overwrite any changes '
                        . 'made in the meantime. Stop the server first, then edit and save. '
                        . '(Locked fields are managed via the Startup tab either way.)')
                ->columnSpanFull()
                ->schema([
                    Actions::make($this->controlActions('_top'))
                        ->columnSpanFull(),
                    Fieldset::make('Managed by the panel')
                        ->columns(3)
                        ->schema($this->managedFields()),
                    Fieldset::make('World configuration')
                        ->columns(1)
                        ->schema(fn () => $this->worldFields()),
                ])
                ->footerActions($this->controlActions())
                ->footerActionsAlignment(Alignment::Right),
        ]);
    }

    /**
     * The section's control actions - rendered in the section header AND
     * footer (same builders, suffixed names, since Filament registers actions
     * by name).
     *
     * @return Action[]
     */
    protected function controlActions(string $suffix = ''): array
    {
        return [
            Action::make("add_setting{$suffix}")
                ->button()
                ->outlined()
                ->size(Size::Medium)
                ->label('Add setting')
                ->icon(TablerIcon::Plus)
                ->color('gray')
                ->visible(fn () => $this->canEdit())
                ->schema([
                    TextInput::make('key')
                        ->label('Setting')
                        ->required()
                        ->regex('/^[A-Za-z0-9_]+$/')
                        ->placeholder('e.g. RandomizerSeed'),
                    TextInput::make('value')
                        ->label('Value'),
                ])
                ->action(function (array $data) {
                    $key = trim($data['key']);

                    if (array_key_exists($key, self::PANEL_MANAGED_KEYS)) {
                        Notification::make()->title("{$key} is managed by the panel")->body('Edit it via the Startup tab instead.')->danger()->send();

                        return;
                    }
                    if (array_key_exists($key, $this->data['options'] ?? [])) {
                        Notification::make()->title("{$key} already exists")->warning()->send();

                        return;
                    }

                    $this->data['options'][$key] = trim((string) ($data['value'] ?? ''));

                    Notification::make()->title("{$key} added")->body('Use "Save to file" to persist it.')->success()->send();
                }),
            Action::make("reset_all{$suffix}")
                ->button()
                ->outlined()
                ->size(Size::Medium)
                ->label('Reset all to defaults')
                ->icon(TablerIcon::Restore)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reset all world configuration to defaults?')
                ->modalDescription('Every world-configuration value is replaced with the game default from '
                    . config('palworld-admin.defaults_path') . ' and the file is saved immediately. '
                    . 'Settings you added that are not part of the defaults are removed. '
                    . 'Panel-managed settings are not affected.')
                ->visible(fn () => $this->canEdit())
                ->action(fn () => $this->resetAllToDefaults()),
            Action::make("save{$suffix}")
                ->button()
                ->outlined()
                ->size(Size::Medium)
                ->label('Save to file')
                ->icon(TablerIcon::DeviceFloppy)
                ->visible(fn () => $this->canEdit())
                ->action(fn () => $this->save()),
            Action::make("stop_server{$suffix}")
                ->button()
                ->outlined()
                ->size(Size::Medium)
                ->label('Stop server to edit')
                ->icon(TablerIcon::PlayerStop)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Players will be disconnected. The world and current settings are written to disk during shutdown (~15 seconds). Editing unlocks automatically once the server is offline.')
                ->visible(fn (Server $server) => !$this->serverStopped() && (user()?->can(SubuserPermission::ControlStop, $server) ?? false))
                ->disabled(fn () => $this->awaitingTarget !== null)
                ->action(function (Server $server) {
                    app(DaemonServerRepository::class)->setServer($server)->power('stop');

                    $this->awaitingTarget = 'stopped';
                    $this->awaitingPolls = 0;
                }),
            Action::make("start_server{$suffix}")
                ->button()
                ->outlined()
                ->size(Size::Medium)
                ->label('Start server')
                ->icon(TablerIcon::PlayerPlay)
                ->color('success')
                ->visible(fn (Server $server) => $this->serverStopped() && (user()?->can(SubuserPermission::ControlStart, $server) ?? false))
                ->disabled(fn () => $this->awaitingTarget !== null)
                ->action(function (Server $server) {
                    app(DaemonServerRepository::class)->setServer($server)->power('start');

                    $this->awaitingTarget = 'running';
                    $this->awaitingPolls = 0;
                }),
        ];
    }

    /** @return TextInput[] */
    protected function managedFields(): array
    {
        return collect(self::PANEL_MANAGED_KEYS)->map(function (string $source, string $key) {
            $origin = str_contains($source, ' ') ? $source : "the {$source} Startup variable";

            return TextInput::make("managed.{$key}")
                ->label($key)
                ->disabled()
                ->dehydrated(false)
                ->password(str_contains($key, 'Password'))
                // key present with an empty value is a normal state - only a
                // truly absent key gets the "will be seeded" placeholder
                ->placeholder(fn () => array_key_exists($key, (array) ($this->data['managed'] ?? []))
                    ? null
                    : 'not in file yet - added on next save')
                ->hintIcon(TablerIcon::Lock, tooltip: "Overwritten on every server start from {$origin} - edit it there, not here.");
        })->values()->all();
    }

    /**
     * One editable field per world setting currently in the file, matching the
     * managed-fieldset style. Add/remove happens via the "Add setting" footer
     * action and the per-row remove button; both only persist on save.
     *
     * @return TextInput[]
     */
    protected function worldFields(): array
    {
        $canUpdate = $this->canEdit();

        return collect($this->data['options'] ?? [])
            ->map(fn ($value, string $key) => TextInput::make("options.{$key}")
                ->label($key)
                ->inlineLabel()
                ->disabled(!$canUpdate)
                ->live(onBlur: true)
                ->suffixActions([
                    Action::make("reset-{$key}")
                        ->icon(TablerIcon::ArrowBackUp)
                        ->color('gray')
                        ->tooltip(fn () => $this->isAtDefault($key) ? 'Already at the game default' : 'Reset to the game default')
                        ->visible($canUpdate)
                        ->disabled(fn () => $this->isAtDefault($key))
                        ->action(function () use ($key) {
                            try {
                                $defaults = $this->defaults();
                            } catch (\Exception $e) {
                                Notification::make()->title('Could not read ' . config('palworld-admin.defaults_path'))->body($e->getMessage())->danger()->send();

                                return;
                            }

                            if (!array_key_exists($key, $defaults)) {
                                Notification::make()->title("No game default known for {$key}")->warning()->send();

                                return;
                            }

                            $this->data['options'][$key] = $defaults[$key];

                            Notification::make()->title("{$key} reset to default")->body('Use "Save to file" to persist it.')->success()->send();
                        }),
                    Action::make("remove-{$key}")
                        ->icon(TablerIcon::X)
                        ->color('danger')
                        ->tooltip('Remove this setting from the file')
                        ->visible($canUpdate)
                        ->requiresConfirmation()
                        ->modalHeading("Remove {$key}?")
                        ->modalDescription('The setting is removed from PalWorldSettings.ini on the next save; the game will fall back to its default.')
                        ->action(function () use ($key) {
                            unset($this->data['options'][$key]);

                            Notification::make()->title("{$key} removed")->body('Use "Save to file" to persist it.')->success()->send();
                        }),
                ]))
            ->values()
            ->all();
    }

    public function save(): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        if (!$this->serverStopped()) {
            Notification::make()
                ->title('Stop the server before saving')
                ->body('Palworld overwrites the settings file on shutdown - changes saved while it runs would be lost.')
                ->danger()
                ->send();

            return;
        }

        $submitted = [];
        foreach ((array) ($this->form->getState()['options'] ?? []) as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '' && !array_key_exists($key, self::PANEL_MANAGED_KEYS)) {
                $submitted[$key] = trim((string) $value);
            }
        }

        if ($submitted === []) {
            Notification::make()->title('Refusing to save an empty settings list')->danger()->send();

            return;
        }

        try {
            $ini = $this->readIni();
            $options = OptionSettings::parseIni($ini);

            $merged = [];
            foreach ($options->values as $key => $value) {
                if (array_key_exists($key, self::PANEL_MANAGED_KEYS)) {
                    $merged[$key] = $value; // panel-managed: file value wins, parser refreshes it on boot
                } elseif (array_key_exists($key, $submitted)) {
                    $merged[$key] = $submitted[$key];
                }
                // keys removed in the form are dropped
            }
            foreach ($submitted as $key => $value) {
                if (!array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                }
            }
            // Seed managed keys missing from the file - the config parser can
            // only update existing keys, so absent ones would never activate.
            foreach (array_keys(self::PANEL_MANAGED_KEYS) as $key) {
                if (!array_key_exists($key, $merged)) {
                    $merged[$key] = '';
                }
            }

        } catch (\Exception $e) {
            Notification::make()->title('Failed to save settings')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->persist($ini, $options, $merged);
    }

    /**
     * Reset-all: rebuild the tuple in the DEFAULTS-FILE order (a normal save
     * preserves the current file order instead), carrying panel-managed values
     * over from the current file.
     */
    protected function resetAllToDefaults(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileUpdate, $this->getRecord()), 403);

        if (!$this->serverStopped()) {
            return;
        }

        try {
            $ini = $this->readIni();
            $options = OptionSettings::parseIni($ini);
            $defaultsFull = OptionSettings::parseIni(
                $this->fileRepository()->getContent(config('palworld-admin.defaults_path'), config('panel.files.max_edit_size'))
            )->values;
        } catch (\Exception $e) {
            Notification::make()->title('Could not read ' . config('palworld-admin.defaults_path'))->body($e->getMessage())->danger()->send();

            return;
        }

        $merged = [];
        foreach ($defaultsFull as $key => $default) {
            $merged[$key] = array_key_exists($key, self::PANEL_MANAGED_KEYS)
                ? ($options->values[$key] ?? '')
                : $default;
        }
        // managed keys the defaults file doesn't know (or that only exist in
        // the current file) still need preserving/seeding
        foreach (array_keys(self::PANEL_MANAGED_KEYS) as $key) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $options->values[$key] ?? '';
            }
        }

        $this->persist($ini, $options, $merged);
    }

    private function persist(string $ini, OptionSettings $options, array $merged): void
    {
        try {
            $options->replaceValues($merged);

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

        $this->fillForm();
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
