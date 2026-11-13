<?php

namespace Cosmii02\ModpackManager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Panel;

class ModpackManagerPlugin implements Plugin, HasPluginSettings
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'modpack-manager';
    }

    public function register(Panel $panel): void
    {
        $id  = str($panel->getId())->title();
        $dir = plugin_path($this->getId(), "src/Filament/{$id}/Pages");

        // Only the Server panel ships pages; guard against missing directories
        // so page discovery doesn't throw on panels without any pages.
        if (is_dir($dir)) {
            $panel->discoverPages(
                $dir,
                "Cosmii02\\ModpackManager\\Filament\\{$id}\\Pages"
            );
        }
    }

    public function boot(Panel $panel): void {}

    public static function make(): static
    {
        return app(static::class);
    }

    // ─── HasPluginSettings ────────────────────────────────────────────────────

    // Only the keys the settings form actually binds to; the rest of the config
    // isn't user-editable. The ->default()s on the fields below are a harmless
    // fallback in case the panel ever renders the form unfilled.
    public function getSettingsFormData(): array
    {
        return [
            'curseforge_api_key' => config('modpack-manager.curseforge_api_key'),
            'modrinth_token'     => config('modpack-manager.modrinth_token'),
            'required_egg_tags'  => config('modpack-manager.required_egg_tags'),
            'store_metadata'     => (bool) config('modpack-manager.store_metadata'),
            'navigation_sort'    => (int) config('modpack-manager.navigation_sort'),
            'remote_download_concurrency' => (int) config('modpack-manager.remote_download_concurrency'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('CurseForge')
                ->description('Required for browsing and installing modpacks from CurseForge.')
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable()
                        ->placeholder('Enter your CurseForge API key')
                        ->helperText('Get your key at https://console.curseforge.com')
                        ->default(fn () => config('modpack-manager.curseforge_api_key')),
                ]),

            Section::make('Modrinth')
                ->description('Optional – only needed for private/authenticated Modrinth access. Public browsing works without a token.')
                ->schema([
                    TextInput::make('modrinth_token')
                        ->label('Personal Access Token (optional)')
                        ->password()
                        ->revealable()
                        ->placeholder('mrp_xxxxxxxx')
                        ->helperText('Create a token at https://modrinth.com/settings/pats')
                        ->default(fn () => config('modpack-manager.modrinth_token')),
                ]),

            Section::make('Server visibility')
                ->description('The Modpacks page only appears on servers whose egg has one of these tags (case-insensitive). Leave empty to show it on every server.')
                ->schema([
                    TagsInput::make('required_egg_tags')
                        ->label('Required egg tags')
                        ->placeholder('Add a tag')
                        ->helperText('Default: minecraft. Press Enter to add each tag.')
                        ->default(fn () => config('modpack-manager.required_egg_tags')),
                ]),

            Section::make('Installed-pack tracking')
                ->description('Optionally record the installed modpack inside each server\'s own files so the current pack survives database resets and panel re-installs.')
                ->schema([
                    Toggle::make('store_metadata')
                        ->label('Store modpack info in server files')
                        ->helperText('Writes a .modpack-manager.json file to each server after install. The Modpacks page reads it to recover the current pack when no install record exists. Off by default.')
                        ->default(fn () => (bool) config('modpack-manager.store_metadata')),
                ]),

            Section::make('Sidebar')
                ->description('Control where the Modpacks entry appears in the server-panel sidebar.')
                ->schema([
                    TextInput::make('navigation_sort')
                        ->label('Sidebar position')
                        ->numeric()
                        ->step(1)
                        ->placeholder('50')
                        ->helperText('Sort weight for the sidebar entry. Lower numbers appear higher up; most core pages sit between 0 and 100. Default: 50.')
                        ->default(fn () => (int) config('modpack-manager.navigation_sort')),
                ]),

            Section::make('Downloads')
                ->description('How many files each server downloads simultaneously when installing a modpack.')
                ->schema([
                    TextInput::make('remote_download_concurrency')
                        ->label('Concurrent downloads')
                        ->numeric()
                        ->step(1)
                        ->minValue(1)
                        ->placeholder('3')
                        ->helperText('Number of files pulled onto the server at once during install. Each file is fetched by Wings directly from the source (e.g. CurseForge\'s CDN), so this caps both Wings\' simultaneous remote downloads and concurrent CurseForge fetches. 3 is a safe default; raise it only if you know your Wings config and API limits allow more.')
                        ->default(fn () => (int) config('modpack-manager.remote_download_concurrency')),
                ]),

            Section::make('About')
                ->schema([
                    Placeholder::make('info')
                        ->label('')
                        ->content('API keys are stored in the panel\'s .env file and are never exposed to server users.'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [];

        if (array_key_exists('curseforge_api_key', $data)) {
            $values['MODPACK_MANAGER_CURSEFORGE_API_KEY'] = trim((string) $data['curseforge_api_key']);
        }

        if (array_key_exists('modrinth_token', $data)) {
            $values['MODPACK_MANAGER_MODRINTH_TOKEN'] = trim((string) $data['modrinth_token']);
        }

        if (array_key_exists('required_egg_tags', $data)) {
            $tags = is_array($data['required_egg_tags']) ? $data['required_egg_tags'] : [];
            $tags = array_values(array_filter(
                array_map(fn ($t) => trim((string) $t), $tags),
                fn ($t) => $t !== ''
            ));
            $values['MODPACK_MANAGER_EGG_TAGS'] = implode(',', $tags);
        }

        if (array_key_exists('store_metadata', $data)) {
            $values['MODPACK_MANAGER_STORE_METADATA'] = $data['store_metadata'] ? 'true' : 'false';
        }

        if (array_key_exists('navigation_sort', $data)) {
            $values['MODPACK_MANAGER_NAV_SORT'] = (string) (int) $data['navigation_sort'];
        }

        if (array_key_exists('remote_download_concurrency', $data)) {
            $values['MODPACK_MANAGER_REMOTE_DOWNLOAD_CONCURRENCY'] = (string) max(1, (int) $data['remote_download_concurrency']);
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title('Modpack Manager settings saved.')
            ->success()
            ->send();
    }
}
