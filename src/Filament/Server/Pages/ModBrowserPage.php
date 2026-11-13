<?php

namespace Cosmii02\ModpackManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use Cosmii02\ModpackManager\Services\CurseForgeService;
use Cosmii02\ModpackManager\Services\HangarService;
use Cosmii02\ModpackManager\Services\ModrinthService;
use Cosmii02\ModpackManager\Services\SpigetService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-panel page for browsing and installing INDIVIDUAL mods or plugins
 * (as opposed to whole modpacks — that's ModpackBrowserPage).
 *
 * The page auto-detects, from the server's egg, whether it runs a mod loader
 * (Forge/NeoForge/Fabric/Quilt) or a plugin platform (Paper/Purpur/Spigot/…):
 *   - modded egg   → "Mods",    installed into  /mods
 *   - plugin egg   → "Plugins", installed into  /plugins
 * The user can flip the mode manually if detection is wrong.
 *
 * Installing a single item is cheap (one Wings download), so — unlike the modpack
 * installer — it runs synchronously in the Livewire request rather than via a
 * queued job. No egg switch, no reinstall.
 *
 * URL: /server/{server}/mods
 */
class ModBrowserPage extends Page
{
    /**
     * Same gate as the Modpacks page: installing/removing content changes server
     * files, so require the reinstall authority. Owner + admins always pass.
     * Change this one line to loosen/tighten.
     */
    private const MANAGE_PERMISSION = SubuserPermission::SettingsReinstall;

    /** Modrinth version loaders to offer per mode. */
    private const MOD_LOADERS    = ['forge', 'neoforge', 'fabric', 'quilt'];
    private const PLUGIN_LOADERS = ['bukkit', 'spigot', 'paper', 'purpur', 'folia', 'sponge'];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationLabel = 'Mods';
    protected static ?string $slug            = 'mods';
    protected string         $view            = 'modpack-manager::filament.server.pages.mod-browser-page';

    /**
     * Sit directly below the Modpacks entry: it uses `navigation_sort` (default 50),
     * so we default to that + 1. Follows the Modpacks page even if an admin moves it.
     */
    public static function getNavigationSort(): ?int
    {
        return (int) config('modpack-manager.navigation_sort', 50) + 1;
    }

    // ─── State ────────────────────────────────────────────────────────────────

    /** 'mods' | 'plugins' — detected from the egg, user-overridable. */
    public string $mode = 'mods';

    public string $search   = '';
    public string $provider = 'all';    // 'all' or one of providersForMode()

    // Facet filters.
    public string $filterVersion = '';
    public array  $filterLoaders = [];
    public array  $filterCategories = [];

    /** @var array<int, array<string, mixed>> */
    public array  $items          = [];
    public bool   $isLoading      = false;
    public bool   $isLoadingMore  = false;
    public bool   $hasMore        = false;
    public int    $page           = 0;
    public int    $pageSize       = 20;
    public string $errorMsg       = '';
    public array  $providerErrors = [];

    // Description/gallery modal state
    public bool    $showInfoModal = false;
    public ?array  $infoItem      = null;
    public string  $infoMode      = 'description';
    public string  $infoError     = '';

    // Install drawer state
    public bool    $showModal        = false;
    public ?array  $selectedItem     = null;
    public array   $versions         = [];
    public bool    $versionsLoading  = false;
    public ?string $selectedVersion  = null;
    public string  $versionsNote     = '';

    // Installed-files list (jars in the target folder)
    /** @var array<int, array{name:string, sizeLabel:string}> */
    public array $installedFiles = [];

    // Whether the current user may install/remove (drives the UI; enforced server-side too).
    public bool $canManage = false;

    private const MOD_PROVIDERS    = ['curseforge', 'modrinth'];
    private const PLUGIN_PROVIDERS = ['curseforge', 'modrinth', 'spigot', 'hangar'];

    /** Platforms that load Bukkit/Spigot plugins (SpigotMC resources). */
    private const SPIGOT_PLATFORMS = ['bukkit', 'spigot', 'paper', 'purpur'];
    /** Platforms that load Paper plugins (Hangar's PAPER builds). */
    private const HANGAR_PLATFORMS = ['paper', 'purpur'];

    /**
     * Shared category pills for individual mods. Each key maps one friendly label
     * to the closest category used by each provider.
     */
    private const MOD_CATEGORY_FILTERS = [
        'performance' => [
            'label' => 'Performance',
            'modrinth' => ['optimization'],
            'curseforge' => ['performance'],
        ],
        'cosmetics' => [
            'label' => 'Cosmetics',
            'modrinth' => ['decoration'],
            'curseforge' => ['cosmetic'],
        ],
        'creatures' => [
            'label' => 'Creatures',
            'modrinth' => ['mobs'],
            'curseforge' => ['mobs'],
        ],
        'technology' => [
            'label' => 'Technology',
            'modrinth' => ['technology'],
            'curseforge' => ['technology'],
        ],
        'magic' => [
            'label' => 'Magic',
            'modrinth' => ['magic'],
            'curseforge' => ['magic'],
        ],
        'worldgen' => [
            'label' => 'World Gen',
            'modrinth' => ['worldgen'],
            'curseforge' => ['world gen', 'world generation'],
        ],
        'storage' => [
            'label' => 'Storage',
            'modrinth' => ['storage'],
            'curseforge' => ['storage'],
        ],
        'utility' => [
            'label' => 'Utility / QoL',
            'modrinth' => ['utility'],
            'curseforge' => ['utility & qol', 'server utility'],
        ],
        'food' => [
            'label' => 'Food',
            'modrinth' => ['food'],
            'curseforge' => ['food'],
        ],
        'equipment' => [
            'label' => 'Equipment',
            'modrinth' => ['equipment'],
            'curseforge' => ['armor, tools, and weapons', 'armor tools and weapons'],
        ],
    ];

    // ─── Authorization / visibility ─────────────────────────────────────────────

    /**
     * Show the page (and its nav entry) only on servers whose egg carries one of
     * the configured required tags (default "minecraft") and to users with the
     * manage permission — same rules as the Modpacks page.
     */
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return parent::canAccess()
            && $server instanceof Server
            && self::eggHasAllowedTag($server)
            && user()?->can(self::MANAGE_PERMISSION, $server);
    }

    private static function eggHasAllowedTag(Server $server): bool
    {
        $required = array_map('strtolower', config('modpack-manager.required_egg_tags', ['minecraft']));

        if (empty($required)) {
            return true;
        }

        foreach ($server->egg?->tags ?? [] as $tag) {
            if (in_array(strtolower(trim((string) $tag)), $required, true)) {
                return true;
            }
        }

        return false;
    }

    private function userCanManage(): bool
    {
        return (bool) user()?->can(self::MANAGE_PERMISSION, $this->getServer());
    }

    private function authorizeManage(): void
    {
        abort_unless($this->userCanManage(), 403);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $server = $this->getServer();

        $this->canManage = $this->userCanManage();

        // Auto-detect Mods vs Plugins from the egg, and seed the loader/platform filter.
        $this->mode = $this->detectMode($server);
        $detectedLoader = $this->detectLoaderOrPlatform($server);
        $this->filterLoaders = $detectedLoader ? [$detectedLoader] : [];

        // Default the version filter to the server's own Minecraft version so the
        // catalogue only shows content compatible with what it actually runs. The
        // user can still override it (incl. "All versions") via the selector.
        $this->filterVersion = $this->detectMcVersion($server) ?? '';

        $this->loadInstalledFiles();
        $this->loadItems();
    }

    // ─── Egg detection ──────────────────────────────────────────────────────────

    /**
     * Decide whether this server runs mods or plugins. A mod-loader version variable
     * is the strongest signal; the egg name/tags are the fallback. Anything else
     * (Paper/Purpur/Spigot/vanilla/proxies) is treated as a plugin platform.
     */
    private static function detectMode(Server $server): string
    {
        $egg  = $server->egg;
        $envs = self::eggEnvVars($egg);

        foreach (['NEOFORGE_VERSION', 'FORGE_VERSION', 'FABRIC_VERSION', 'QUILT_VERSION'] as $var) {
            if (in_array($var, $envs, true)) {
                return 'mods';
            }
        }

        $haystack = self::eggHaystack($egg);
        foreach (['neoforge', 'forge', 'fabric', 'quilt'] as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $haystack)) {
                return 'mods';
            }
        }

        return 'plugins';
    }

    /**
     * Best guess at the specific loader (mods) or platform (plugins) for the default
     * filter, e.g. 'neoforge' or 'paper'. Null when it can't be told.
     */
    private function detectLoaderOrPlatform(Server $server): ?string
    {
        $egg  = $server->egg;
        $envs = $this->eggEnvVars($egg);

        if (in_array('NEOFORGE_VERSION', $envs, true)) return 'neoforge';
        if (in_array('FORGE_VERSION', $envs, true))    return 'forge';
        if (in_array('QUILT_VERSION', $envs, true))    return 'quilt';
        if (in_array('FABRIC_VERSION', $envs, true))   return 'fabric';

        $haystack   = $this->eggHaystack($egg);
        $candidates = $this->mode === 'plugins'
            ? ['purpur', 'paper', 'folia', 'spigot', 'bukkit', 'sponge']
            : ['neoforge', 'forge', 'fabric', 'quilt'];

        foreach ($candidates as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $haystack)) {
                return $kw;
            }
        }

        return null;
    }

    /** @return string[] Upper-cased env-variable names defined by the egg. */
    private static function eggEnvVars($egg): array
    {
        $out = [];
        foreach ($egg?->variables ?? [] as $v) {
            $out[] = strtoupper((string) $v->env_variable);
        }
        return $out;
    }

    /** Lower-cased "name + tags" blob for keyword matching. */
    private static function eggHaystack($egg): string
    {
        return strtolower(trim(($egg?->name ?? '') . ' ' . implode(' ', $egg?->tags ?? [])));
    }

    /**
     * The Minecraft version this server is currently set to, read from its own
     * variable value (falling back to the egg's default) for the usual version
     * variable names. Null when it can't be determined or isn't a plain release
     * number (e.g. "latest"), so browsing isn't force-filtered to a bad value.
     */
    private function detectMcVersion(Server $server): ?string
    {
        $names = ['MC_VERSION', 'MINECRAFT_VERSION', 'VANILLA_VERSION', 'SERVER_VERSION', 'VERSION'];

        // Eager-load explicitly so reading the egg's variables can't trip Pelican's
        // lazy-loading guard (which would otherwise throw and be swallowed below).
        try {
            $server->loadMissing('egg.variables');
        } catch (Throwable) {
            // ignore — we still try what's already loaded
        }

        $egg = $server->egg;
        if (!$egg) {
            return null;
        }

        // Map egg variable id → ENV name, and capture the version-variable defaults.
        $envById  = [];
        $defaults = [];
        foreach ($egg->variables ?? [] as $v) {
            $env = strtoupper((string) $v->env_variable);
            $envById[$v->id] = $env;
            if (in_array($env, $names, true)) {
                $defaults[$env] = trim((string) ($v->default_value ?? ''));
            }
        }

        // The server's actually-set values. Query ServerVariable directly (the same
        // model the installer writes) instead of a relation accessor, so this works
        // regardless of relation naming or lazy-loading being disabled.
        $serverVals = [];
        try {
            foreach (ServerVariable::query()->where('server_id', $server->id)->get() as $sv) {
                $env = $envById[$sv->variable_id] ?? null;
                if ($env !== null) {
                    $serverVals[$env] = (string) $sv->variable_value;
                }
            }
        } catch (Throwable) {
            // fall through to egg defaults
        }

        // Prefer the set value, then the egg default — both in $names priority order.
        foreach ($names as $env) {
            if (isset($serverVals[$env]) && ($mc = $this->cleanMcVersion($serverVals[$env])) !== null) {
                return $mc;
            }
        }
        foreach ($names as $env) {
            if (isset($defaults[$env]) && ($mc = $this->cleanMcVersion($defaults[$env])) !== null) {
                return $mc;
            }
        }

        return null;
    }

    /** Return a plain "1.20.1"-style release from a raw value, or null. */
    private function cleanMcVersion(string $raw): ?string
    {
        $raw = trim($raw);
        return preg_match('/^\d+\.\d+(?:\.\d+)?$/', $raw) ? $raw : null;
    }

    // ─── View helpers ───────────────────────────────────────────────────────────

    /** @return string[] Sources offered for the current mode. */
    public function providersForMode(): array
    {
        return $this->mode === 'plugins' ? self::PLUGIN_PROVIDERS : self::MOD_PROVIDERS;
    }

    /** Human label for the current mode. */
    public function contentNoun(): string
    {
        return $this->mode === 'plugins' ? 'plugin' : 'mod';
    }

    /** The server directory the current mode installs into ('mods' or 'plugins'). */
    private function targetDir(): string
    {
        return $this->mode === 'plugins' ? 'plugins' : 'mods';
    }

    public function getTargetDirLabel(): string
    {
        return '/' . $this->targetDir();
    }

    /**
     * Minecraft versions for the filter. Prefer CurseForge's live list; if that's
     * empty (e.g. no CF API key configured), fall back to Modrinth's public
     * game-version list (needs no key); only if both fail use the static list.
     */
    public function getFilterVersionOptions(): array
    {
        try {
            $versions = app(CurseForgeService::class)->getMinecraftVersions();
        } catch (Throwable) {
            $versions = [];
        }

        if (empty($versions)) {
            try {
                $versions = app(ModrinthService::class)->getMinecraftVersions();
            } catch (Throwable) {
                $versions = [];
            }
        }

        // Last-resort static list (both live sources unavailable). Kept reasonably
        // current — the newest release line plus the historically popular versions —
        // so an offline panel still offers sensible choices.
        $versions = !empty($versions) ? $versions : [
            '26.2', '26.1.2', '26.1',
            '1.21.11', '1.21.8', '1.21.4', '1.21.1', '1.21',
            '1.20.6', '1.20.4', '1.20.1', '1.20',
            '1.19.2', '1.18.2', '1.16.5', '1.12.2', '1.7.10',
        ];

        // Make sure the currently-selected version (e.g. the auto-detected server
        // version) is always present so the selector can render it as chosen.
        if ($this->filterVersion !== '' && !in_array($this->filterVersion, $versions, true)) {
            array_unshift($versions, $this->filterVersion);
        }

        return $versions;
    }

    /** Loader (mods) or platform (plugins) options for the filter. */
    public function getFilterLoaderOptions(): array
    {
        return $this->mode === 'plugins'
            ? ['paper' => 'Paper', 'purpur' => 'Purpur', 'spigot' => 'Spigot', 'bukkit' => 'Bukkit', 'folia' => 'Folia', 'sponge' => 'Sponge']
            : ['forge' => 'Forge', 'neoforge' => 'NeoForge', 'fabric' => 'Fabric', 'quilt' => 'Quilt'];
    }

    /** Friendly cross-provider category pills for individual mods. */
    public function getCategoryFilterOptions(): array
    {
        if ($this->mode !== 'mods') {
            return [];
        }

        $options = [];
        foreach (self::MOD_CATEGORY_FILTERS as $slug => $definition) {
            $options[$slug] = $definition['label'];
        }

        return $options;
    }

    private function activeFilters(): array
    {
        $filters = [];

        if ($this->filterVersion !== '') {
            $filters['gameVersion'] = $this->filterVersion;
        }
        if (!empty($this->filterLoaders)) {
            $filters['loaders'] = array_values($this->filterLoaders);
            if (count($this->filterLoaders) === 1) {
                $filters['loader'] = $this->filterLoaders[0];
            }
        }
        if ($this->mode === 'mods' && !empty($this->filterCategories)) {
            $filters['contentCategories'] = array_values($this->filterCategories);
        }

        return $filters;
    }

    public function getActiveFilterCount(): int
    {
        return count($this->filterLoaders) + count($this->filterCategories);
    }

    public function getActiveFilterChips(): array
    {
        $chips = [];

        $labels = $this->getFilterLoaderOptions();
        foreach ($this->filterLoaders as $loader) {
            $chips[] = [
                'type' => 'loader',
                'value' => $loader,
                'label' => $labels[$loader] ?? ucfirst($loader),
            ];
        }

        $categoryLabels = $this->getCategoryFilterOptions();
        foreach ($this->filterCategories as $category) {
            $chips[] = [
                'type' => 'category',
                'value' => $category,
                'label' => $categoryLabels[$category] ?? ucfirst($category),
            ];
        }

        return $chips;
    }

    public function removeFilterChip(string $type, string $value = ''): void
    {
        if ($type === 'loader') {
            $value = strtolower(trim($value));
            $this->filterLoaders = array_values(array_filter($this->filterLoaders, fn ($loader) => $loader !== $value));
        } elseif ($type === 'category') {
            $value = strtolower(trim($value));
            $this->filterCategories = array_values(array_filter($this->filterCategories, fn ($category) => $category !== $value));
        } else {
            return;
        }

        $this->resetResults();
        $this->loadItems();
    }

    public function toggleLoader(string $loader): void
    {
        $loader = strtolower(trim($loader));
        if (!array_key_exists($loader, $this->getFilterLoaderOptions())) {
            return;
        }

        if (in_array($loader, $this->filterLoaders, true)) {
            $this->filterLoaders = array_values(array_filter($this->filterLoaders, fn ($item) => $item !== $loader));
        } else {
            $this->filterLoaders[] = $loader;
            $this->filterLoaders = array_values(array_unique($this->filterLoaders));
        }
    }

    public function toggleCategory(string $category): void
    {
        $category = strtolower(trim($category));
        if (!array_key_exists($category, $this->getCategoryFilterOptions())) {
            return;
        }

        if (in_array($category, $this->filterCategories, true)) {
            $this->filterCategories = array_values(array_filter($this->filterCategories, fn ($item) => $item !== $category));
        } else {
            $this->filterCategories[] = $category;
            $this->filterCategories = array_values(array_unique($this->filterCategories));
        }
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    public function setMode(string $mode): void
    {
        $mode = $mode === 'plugins' ? 'plugins' : 'mods';
        if ($mode === $this->mode) {
            return;
        }

        $this->mode = $mode;
        if (!in_array($this->provider, $this->providersForMode(), true)) {
            $this->provider = 'all';
        }
        $detectedLoader = $this->detectLoaderOrPlatform($this->getServer());
        $this->filterLoaders = $detectedLoader ? [$detectedLoader] : [];
        $this->filterCategories = [];
        $this->search = '';
        $this->loadInstalledFiles();
        $this->resetResults();
        $this->loadItems();
    }

    public function searchItems(): void
    {
        $this->resetResults();
        $this->loadItems();
    }

    public function applyFilters(): void
    {
        $this->resetResults();
        $this->loadItems();
    }

    public function clearFilters(): void
    {
        $this->filterLoaders = [];
        $this->filterCategories = [];
        $this->resetResults();
        $this->loadItems();
    }

    public function setProvider(string $provider): void
    {
        $this->provider = in_array($provider, $this->providersForMode(), true) ? $provider : 'all';
        $this->search = '';
        $this->resetResults();
        $this->loadItems();
    }

    public function loadMore(): void
    {
        if ($this->isLoading || $this->isLoadingMore || !$this->hasMore) {
            return;
        }

        $this->page++;
        $this->loadItems(true);
    }

    public function retryProviderSearches(): void
    {
        $this->resetResults();
        $this->loadItems();
    }

    public function openItemInfo(string|int $itemId, string $provider, string $mode = 'description'): void
    {
        if (!in_array($mode, ['description', 'gallery'], true)) {
            $mode = 'description';
        }

        $this->infoMode = $mode;
        $this->infoError = '';
        $this->infoItem = collect($this->items)->first(
            fn ($item) => (string) ($item['id'] ?? '') === (string) $itemId
                && ($item['provider'] ?? null) === $provider
        );
        $this->showInfoModal = true;

        try {
            $details = $this->fetchSingleItem($itemId, $provider);
            $this->infoItem = array_merge($this->infoItem ?? [], $details);

            if ($provider === 'curseforge') {
                $description = app(CurseForgeService::class)->getDescription((int) $itemId);
                if ($description !== '') {
                    $this->infoItem['description'] = $description;
                }
            }
        } catch (Throwable $e) {
            $this->infoError = 'Could not load the full ' . $this->contentNoun() . ' details.';
            Log::info('[ModpackManager] Individual content detail lookup failed', [
                'provider' => $provider,
                'id' => (string) $itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function closeItemInfo(): void
    {
        $this->showInfoModal = false;
        $this->infoItem = null;
        $this->infoError = '';
    }

    public function externalUrl(?string $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    public function itemWebsiteUrl(array $item): ?string
    {
        $url = $this->externalUrl($item['websiteUrl'] ?? null);
        $slug = trim((string) ($item['slug'] ?? ''));
        $provider = $item['provider'] ?? null;

        if ($provider === 'curseforge' && $slug !== '') {
            $path = $this->mode === 'plugins' ? 'bukkit-plugins' : 'mc-mods';
            return $url ?? 'https://www.curseforge.com/minecraft/' . $path . '/' . rawurlencode($slug);
        }
        if ($provider === 'modrinth' && $slug !== '') {
            $path = $this->mode === 'plugins' ? 'plugin' : 'mod';
            if ($url === null || str_contains($url, '/modpack/')) {
                return 'https://modrinth.com/' . $path . '/' . rawurlencode($slug);
            }
        }

        return $url;
    }

    /**
     * Open the install drawer for a given item, then load its versions in a
     * follow-up request (wire:init) so the drawer pops instantly.
     */
    public function openModal(string|int $itemId, ?string $provider = null): void
    {
        $this->authorizeManage();

        $item = collect($this->items)->first(
            fn ($m) => (string) ($m['id'] ?? '') === (string) $itemId
                && ($provider === null || ($m['provider'] ?? null) === $provider)
        );

        if (!$item) {
            try {
                $item = $this->fetchSingleItem($itemId, $provider ?? $this->provider);
            } catch (Throwable) {
                Notification::make()->title('Could not load ' . $this->contentNoun() . ' details.')->danger()->send();
                return;
            }
        }

        $this->selectedItem    = $item;
        $this->versions        = [];
        $this->selectedVersion = null;
        $this->versionsNote    = '';
        $this->versionsLoading = true;
        $this->showModal       = true;
    }

    public function closeModal(): void
    {
        $this->showModal       = false;
        $this->selectedItem    = null;
        $this->versions        = [];
        $this->selectedVersion = null;
        $this->versionsNote    = '';
    }

    /**
     * Default-select the newest loaded version that fits the server: prefer one
     * matching BOTH the selected Minecraft version and loader/platform, then MC
     * alone, and only fall back to the newest overall when nothing is compatible.
     * Versions arrive newest-first, so the first match is the latest compatible one.
     */
    private function preferredVersionId(): string
    {
        // Pre-release channels (e.g. Hangar snapshots) only win when nothing stable exists.
        $stable = array_values(array_filter($this->versions, fn ($v) => empty($v['prerelease'])));
        $candidates = !empty($stable) ? $stable : $this->versions;

        $mc = strtolower(trim($this->filterVersion));
        $loaders = array_values(array_filter(array_map(fn ($loader) => strtolower(trim((string) $loader)), $this->filterLoaders)));

        $tags = function (array $v): array {
            // CurseForge encodes MC versions + loader names in gameVersions; Modrinth
            // splits them across gameVersions + loaders. Merge both, lower-cased.
            return array_map(
                fn ($g) => strtolower(trim((string) $g)),
                array_merge($v['gameVersions'] ?? [], $v['loaders'] ?? [])
            );
        };

        if ($mc !== '') {
            // MC + loader.
            if (!empty($loaders)) {
                foreach ($candidates as $v) {
                    $t = $tags($v);
                    if (in_array($mc, $t, true) && count(array_intersect($loaders, $t)) > 0) {
                        return (string) $v['id'];
                    }
                }
            }
            // MC only.
            foreach ($candidates as $v) {
                if (in_array($mc, $tags($v), true)) {
                    return (string) $v['id'];
                }
            }
        }

        return (string) $candidates[0]['id'];
    }

    /**
     * The plain Minecraft release numbers a given version supports, newest first.
     * CurseForge mixes loader names into gameVersions (e.g. ["1.20.1","NeoForge"]);
     * Modrinth lists pure MC versions — either way we keep only "1.20.1"-shaped
     * entries so the drawer can show exactly which MC versions a file is for.
     *
     * @param  array<string, mixed>  $ver
     * @return string[]
     */
    public function supportedMcVersions(array $ver): array
    {
        $out = [];
        foreach ($ver['gameVersions'] ?? [] as $g) {
            $g = trim((string) $g);
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $g)) {
                $out[$g] = true;
            }
        }

        $out = array_keys($out);
        usort($out, 'version_compare');

        return array_reverse($out);
    }

    /**
     * Map of version-id → supported MC versions for the loaded drawer versions,
     * so the view can show the selected version's compatibility reactively.
     *
     * @return array<string, string[]>
     */
    public function versionMcMap(): array
    {
        $map = [];
        foreach ($this->versions as $v) {
            $map[(string) ($v['id'] ?? '')] = $this->supportedMcVersions($v);
        }

        return $map;
    }

    public function loadVersions(): void
    {
        if (!$this->selectedItem) {
            return;
        }

        $this->versionsLoading = true;

        try {
            $id = $this->selectedItem['id'];

            // Hand both providers the active filters so the picker lists builds
            // for this server rather than whatever released most recently across
            // every Minecraft version.
            $filters = $this->activeFilters();

            $this->versionsNote = '';

            switch ($this->selectedItem['provider'] ?? null) {
                case 'curseforge':
                    $this->versions = app(CurseForgeService::class)->getFiles((int) $id, $filters);
                    break;
                case 'spigot':
                    $this->versions = app(SpigetService::class)->getVersions((int) $id, $filters);
                    if (empty($this->versions)) {
                        $this->versionsNote = 'SpigotMC only allows browser downloads for this plugin (premium, external link or non-jar file). Get it from its SpigotMC page instead.';
                    }
                    break;
                case 'hangar':
                    $this->versions = app(HangarService::class)->getVersions((string) $id, $filters);
                    if (empty($this->versions)) {
                        $this->versionsNote = 'This Hangar project has no Paper jar hosted on Hangar (it links to an external download). Get it from its project page instead.';
                    }
                    break;
                default:
                    $loaders = $this->mode === 'plugins' ? self::PLUGIN_LOADERS : self::MOD_LOADERS;
                    $this->versions = app(ModrinthService::class)->getVersions((string) $id, $loaders, $filters);
            }

            if (!empty($this->versions)) {
                $this->selectedVersion = $this->preferredVersionId();
            }
        } catch (Throwable $e) {
            Notification::make()->title('Could not load versions: ' . $e->getMessage())->warning()->send();
        } finally {
            $this->versionsLoading = false;
        }
    }

    /**
     * Download the selected version straight into the target folder via Wings.
     * Synchronous: fires the pull, then briefly polls for the file to land.
     */
    public function installItem(): void
    {
        $this->authorizeManage();

        if (!$this->selectedItem || !$this->selectedVersion) {
            Notification::make()->title('Please select a version.')->warning()->send();
            return;
        }

        $provider = $this->selectedItem['provider'] ?? null;
        $name     = $this->selectedItem['name'] ?? ucfirst($this->contentNoun());

        try {
            [$url, $fileName] = $this->resolveDownload();
        } catch (Throwable $e) {
            Notification::make()->title('Could not resolve download: ' . $e->getMessage())->danger()->send();
            return;
        }

        if (empty($url) || empty($fileName)) {
            Notification::make()->title('No downloadable file for this version.')->danger()->send();
            return;
        }

        $dir    = $this->targetDir();
        $server = $this->getServer();

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($server);

            // Make sure the destination exists (fresh servers may lack it).
            try { $repo->createDirectory($dir, '/'); } catch (Throwable) {}

            $repo->pull($url, '/' . $dir, [
                'filename'   => $fileName,
                'foreground' => false,
            ]);

            // Briefly wait for Wings to finish (single jars are small/fast). If it
            // hasn't settled in time we still report it as started, not failed.
            $landed = $this->waitForFile($repo, $dir, $fileName);
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Individual install failed', ['error' => $e->getMessage()]);
            Notification::make()
                ->title('Install failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return;
        }

        $this->closeModal();
        $this->loadInstalledFiles();

        if ($landed) {
            Notification::make()
                ->title("Installed “{$name}”")
                ->body("Saved to {$this->getTargetDirLabel()}/{$fileName}. Restart the server to load it.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title("Downloading “{$name}”…")
                ->body("Wings is fetching it into {$this->getTargetDirLabel()}. It should appear shortly — refresh the list.")
                ->info()
                ->send();
        }
    }

    /**
     * Remove a jar from the target folder. Only names currently in the listed
     * folder are accepted, so this can't be used to delete arbitrary paths.
     */
    public function removeInstalledFile(string $name): void
    {
        $this->authorizeManage();

        $known = collect($this->installedFiles)->pluck('name')->all();
        if ($name === '' || basename($name) !== $name || !in_array($name, $known, true)) {
            Notification::make()->title('That file is no longer present.')->warning()->send();
            $this->loadInstalledFiles();
            return;
        }

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($this->getServer());
            $repo->deleteFiles('/' . $this->targetDir(), [$name]);
        } catch (Throwable $e) {
            Notification::make()->title('Could not remove file: ' . $e->getMessage())->danger()->send();
            return;
        }

        $this->loadInstalledFiles();

        Notification::make()
            ->title("Removed {$name}")
            ->body('Restart the server for the change to take effect.')
            ->success()
            ->send();
    }

    /**
     * Enable/disable a mod or plugin by renaming .jar <-> .jar.disabled.
     * Nothing is deleted and no provider-specific exclusion list is involved.
     */
    public function toggleInstalledFile(string $name): void
    {
        $this->authorizeManage();

        if (!$this->installedFileIsKnown($name)) {
            Notification::make()->title('That file is no longer present.')->warning()->send();
            $this->loadInstalledFiles();
            return;
        }

        $disabled = str_ends_with(strtolower($name), '.disabled');
        $target = $disabled ? substr($name, 0, -9) : $name . '.disabled';

        if ($target === '' || basename($target) !== $target) {
            Notification::make()->title('Could not determine the target filename.')->danger()->send();
            return;
        }

        $known = collect($this->installedFiles)->pluck('name')->all();
        if (in_array($target, $known, true)) {
            Notification::make()->title("{$target} already exists.")->warning()->send();
            return;
        }

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($this->getServer());
            $repo->renameFiles('/' . $this->targetDir(), [[
                'from' => $name,
                'to' => $target,
            ]]);
        } catch (Throwable $e) {
            Notification::make()->title('Could not change mod state: ' . $e->getMessage())->danger()->send();
            return;
        }

        $this->loadInstalledFiles();

        Notification::make()
            ->title(($disabled ? 'Enabled ' : 'Disabled ') . preg_replace('/\.disabled$/i', '', $name))
            ->body('Restart the server for the change to take effect.')
            ->success()
            ->send();
    }

    public function refreshInstalled(): void
    {
        $this->loadInstalledFiles();
    }

    // ─── Data loading ───────────────────────────────────────────────────────────

    private function resetResults(): void
    {
        $this->page = 0;
        $this->hasMore = false;
        $this->items = [];
        $this->providerErrors = [];
        $this->errorMsg = '';
    }

    private function loadItems(bool $append = false): void
    {
        if ($append) {
            $this->isLoadingMore = true;
        } else {
            $this->isLoading = true;
            $this->items = [];
            $this->providerErrors = [];
        }
        $this->errorMsg = '';

        try {
            $filters = $this->activeFilters();

            if ($this->provider === 'all') {
                [$results, $hasMore] = $this->searchAllProviders($this->search, $filters, $this->page);
            } else {
                $results = $this->searchProvider($this->provider, $this->search, $filters, $this->page, $this->pageSize);
                $hasMore = count($results) >= $this->pageSize;
                unset($this->providerErrors[$this->provider]);
            }

            if ($append) {
                $merged = [];
                foreach (array_merge($this->items, $results) as $item) {
                    $key = ($item['provider'] ?? '') . ':' . (string) ($item['id'] ?? '');
                    $merged[$key] = $item;
                }
                $this->items = array_values($merged);
            } else {
                $this->items = $results;
            }

            $this->hasMore = $hasMore;
        } catch (Throwable $e) {
            $this->errorMsg = $e->getMessage();
            if ($this->provider !== 'all') {
                $this->providerErrors[$this->provider] = $this->providerLabel($this->provider) . ' is temporarily unavailable.';
            }
            Log::warning('[ModpackManager] Failed to load ' . $this->mode, ['error' => $e->getMessage()]);
        } finally {
            $this->isLoading = false;
            $this->isLoadingMore = false;
        }
    }

    private function searchAllProviders(string $query, array $filters, int $page = 0): array
    {
        $providers = $this->providersForMode();
        $perProvider = max(1, (int) ceil($this->pageSize / count($providers)));
        $buckets = [];
        $hasMore = false;

        foreach ($providers as $provider) {
            try {
                $items = $this->searchProvider($provider, $query, $filters, $page, $perProvider);
                unset($this->providerErrors[$provider]);
                $buckets[$provider] = $items;
                $hasMore = $hasMore || count($items) >= $perProvider;
            } catch (Throwable $e) {
                $this->providerErrors[$provider] = $this->providerLabel($provider) . ' is temporarily unavailable.';
                Log::info("[ModpackManager] '{$provider}' {$this->mode} search skipped in combined view", ['error' => $e->getMessage()]);
                $buckets[$provider] = [];
            }
        }

        $merged = [];
        for ($i = 0; $i < $perProvider && count($merged) < $this->pageSize; $i++) {
            foreach ($buckets as $items) {
                if (isset($items[$i])) {
                    $merged[] = $items[$i];
                    if (count($merged) >= $this->pageSize) {
                        break;
                    }
                }
            }
        }

        return [$merged, $hasMore];
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'curseforge' => 'CurseForge',
            'modrinth' => 'Modrinth',
            'spigot' => 'SpigotMC',
            'hangar' => 'Hangar',
            default => ucfirst($provider),
        };
    }

    private function searchProvider(string $provider, string $query, array $filters, int $page = 0, int $pageSize = 20): array
    {
        if ($provider === 'spigot' || $provider === 'hangar') {
            return $this->mode === 'plugins'
                ? $this->searchPluginSite($provider, $query, $filters, $page, $pageSize)
                : [];
        }

        if ($provider === 'curseforge') {
            $classId = $this->mode === 'plugins' ? CurseForgeService::CLASS_PLUGINS : CurseForgeService::CLASS_MODS;
            $filters = $this->categoryFiltersForCurseForge($filters, $classId);
            if (($filters['contentCategoriesUnsupported'] ?? false) === true) {
                return [];
            }
            return app(CurseForgeService::class)->searchContent($query, $classId, $page, $pageSize, $filters);
        }

        $projectType = $this->mode === 'plugins' ? 'plugin' : 'mod';
        $filters = $this->categoryFiltersForModrinth($filters);
        if (($filters['contentCategoriesUnsupported'] ?? false) === true) {
            return [];
        }
        return app(ModrinthService::class)->searchByType($query, $projectType, $page, $pageSize, $filters);
    }

    /**
     * SpigotMC and Hangar have no platform facet that matches ours, so the
     * platform filter decides whether the site applies at all. Spiget cannot
     * filter by Minecraft version either: Spigot's "tested versions" are
     * author-maintained and often stale, so they are shown rather than enforced.
     */
    private function searchPluginSite(string $provider, string $query, array $filters, int $page, int $pageSize): array
    {
        $platforms = $filters['loaders'] ?? [];

        if ($provider === 'spigot') {
            if (!empty($platforms) && empty(array_intersect($platforms, self::SPIGOT_PLATFORMS))) {
                return [];
            }
            return app(SpigetService::class)->search($query, $page, $pageSize);
        }

        if (!empty($platforms) && empty(array_intersect($platforms, self::HANGAR_PLATFORMS))) {
            if (!in_array('folia', $platforms, true)) {
                return [];
            }
            $filters['foliaOnly'] = true;
        }

        return app(HangarService::class)->search($query, $page, $pageSize, $filters);
    }

    private function categoryFiltersForModrinth(array $filters): array
    {
        $selected = $filters['contentCategories'] ?? [];
        unset($filters['contentCategories']);

        if (empty($selected) || $this->mode !== 'mods') {
            return $filters;
        }

        $categories = [];
        foreach ($selected as $key) {
            foreach (self::MOD_CATEGORY_FILTERS[$key]['modrinth'] ?? [] as $slug) {
                $categories[$slug] = true;
            }
        }

        if (empty($categories)) {
            $filters['contentCategoriesUnsupported'] = true;
        } else {
            $filters['categories'] = array_keys($categories);
        }

        return $filters;
    }

    private function categoryFiltersForCurseForge(array $filters, int $classId): array
    {
        $selected = $filters['contentCategories'] ?? [];
        unset($filters['contentCategories']);

        if (empty($selected) || $this->mode !== 'mods') {
            return $filters;
        }

        $wanted = [];
        foreach ($selected as $key) {
            foreach (self::MOD_CATEGORY_FILTERS[$key]['curseforge'] ?? [] as $name) {
                $wanted[$this->normalizeCategoryName($name)] = true;
            }
        }

        $ids = [];
        foreach (app(CurseForgeService::class)->getContentCategories($classId) as $category) {
            if (isset($wanted[$this->normalizeCategoryName((string) ($category['name'] ?? ''))])) {
                $ids[(int) $category['id']] = true;
            }
        }

        if (empty($ids)) {
            $filters['contentCategoriesUnsupported'] = true;
        } else {
            $filters['categoryIds'] = array_keys($ids);
        }

        return $filters;
    }

    private function normalizeCategoryName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\\s+/', ' ', $value) ?? $value);
    }

    private function fetchSingleItem(string|int $id, ?string $provider): array
    {
        if ($provider === 'curseforge') {
            return app(CurseForgeService::class)->getMod((int) $id);
        }
        if ($provider === 'spigot') {
            return app(SpigetService::class)->getResource((int) $id);
        }
        if ($provider === 'hangar') {
            return app(HangarService::class)->getProject((string) $id);
        }

        $projectType = $this->mode === 'plugins' ? 'plugin' : 'mod';
        return app(ModrinthService::class)->getProject((string) $id, $projectType);
    }

    /**
     * Resolve the download URL + filename for the selected version.
     *
     * @return array{0:?string, 1:?string}  [url, fileName]
     */
    private function resolveDownload(): array
    {
        $provider = $this->selectedItem['provider'] ?? null;

        if ($provider === 'curseforge') {
            $file = collect($this->versions)->first(
                fn ($v) => (string) ($v['id'] ?? '') === (string) $this->selectedVersion
            );
            $fileName = $file['fileName'] ?? $file['displayName'] ?? null;
            $url = app(CurseForgeService::class)->getDownloadUrl(
                (int) $this->selectedItem['id'],
                (int) $this->selectedVersion
            );
            return [$url, $fileName];
        }

        // Modrinth, SpigotMC, Hangar: take the primary file (or the first) from the loaded version.
        $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
        $files   = $version['files'] ?? [];
        $file    = collect($files)->firstWhere('primary', true) ?? ($files[0] ?? null);
        $url     = $file['url'] ?? null;

        if ($provider === 'spigot' && is_string($url)) {
            $url = app(SpigetService::class)->resolveDownloadUrl($url);
        }

        return [$url, $file['filename'] ?? null];
    }

    /**
     * Poll the target folder until $fileName appears and its size stops growing,
     * or the short deadline passes. Returns true if the file settled.
     */
    private function waitForFile(DaemonFileRepository $repo, string $dir, string $fileName): bool
    {
        $deadline = time() + 20;
        $lastSize = -1;
        $stable   = 0;

        while (time() < $deadline) {
            usleep(1_800_000); // 1.8s
            $size = $this->remoteFileSize($repo, '/' . $dir, $fileName);

            if ($size === null) {
                continue;
            }
            if ($size > 0 && $size === $lastSize) {
                if (++$stable >= 1) {
                    return true;
                }
            } else {
                $stable = 0;
            }
            $lastSize = $size;
        }

        return $lastSize > 0;
    }

    private function installedFileIsKnown(string $name): bool
    {
        if ($name === '' || basename($name) !== $name) {
            return false;
        }

        return collect($this->installedFiles)->contains(
            fn ($file) => (string) ($file['name'] ?? '') === $name
        );
    }

    private function loadInstalledFiles(): void
    {
        $this->installedFiles = [];

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($this->getServer());
            $entries = $repo->getDirectory('/' . $this->targetDir());
        } catch (Throwable) {
            return; // folder missing / daemon offline — just show nothing
        }

        $files = [];
        foreach ($entries as $entry) {
            $name   = (string) ($entry['name'] ?? '');
            $isFile = (bool) ($entry['file'] ?? true);

            if (!$isFile || !preg_match('/\.jar(\.disabled)?$/i', $name)) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'enabled' => !str_ends_with(strtolower($name), '.disabled'),
                'sizeLabel' => $this->humanBytes((int) ($entry['size'] ?? 0)),
            ];
        }

        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->installedFiles = $files;
    }

    private function remoteFileSize(DaemonFileRepository $repo, string $dir, string $name): ?int
    {
        try {
            foreach ($repo->getDirectory($dir) as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    return (int) ($entry['size'] ?? 0);
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        return $server;
    }

    // ─── Navigation ───────────────────────────────────────────────────────────

    public static function getNavigationLabel(): string
    {
        // In the server panel the tenant (this server) is already bound when the
        // sidebar is built, so we can reflect the egg: a plugin platform reads
        // "Plugins", a mod loader reads "Mods". Falls back to "Mods" off-tenant.
        $server = Filament::getTenant();

        return ($server instanceof Server && self::detectMode($server) === 'plugins')
            ? 'Plugins'
            : 'Mods';
    }

    public function getTitle(): string
    {
        return $this->mode === 'plugins' ? 'Plugin Browser' : 'Mod Browser';
    }
}
