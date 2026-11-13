<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ModrinthService
{
    private const BASE_URL = 'https://api.modrinth.com/v2';

    private PendingRequest $client;

    public function __construct()
    {
        $headers = [
            'User-Agent' => 'pelican-modpack-manager/1.7.1 (+https://github.com/cosmii02/pelican-modpackmanager)',
            'Accept'     => 'application/json',
        ];

        $token = config('modpack-manager.modrinth_token');
        if (!empty($token)) {
            $headers['Authorization'] = $token;
        }

        $this->client = Http::withHeaders($headers)->baseUrl(self::BASE_URL)->timeout(30);
    }

    /**
     * The full list of released Minecraft versions, newest first, from Modrinth's
     * public `tag/game_version` endpoint (no auth required). Used as the fallback
     * source for the version filter when CurseForge has no key / is unavailable.
     * Snapshots and pre-releases are dropped. Cached for a day; only non-empty
     * results are cached. Returns [] on failure.
     *
     * @return string[]
     */
    public function getMinecraftVersions(): array
    {
        $cacheKey = 'modpack-manager:modrinth:mc-versions';

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/tag/game_version');

            if ($response->failed()) {
                Log::warning('[ModpackManager] Modrinth game_version list failed', ['status' => $response->status()]);
                return [];
            }

            $versions = [];
            foreach ($response->json() ?? [] as $v) {
                $str = $v['version'] ?? null;
                // Numeric releases only — skip snapshots/betas ($v['version_type'] === 'release').
                if (($v['version_type'] ?? null) === 'release'
                    && is_string($str) && preg_match('/^\d+\.\d+(\.\d+)?$/', $str)) {
                    $versions[$str] = true;
                }
            }

            $versions = array_keys($versions);
            usort($versions, fn ($a, $b) => version_compare($b, $a)); // newest first

            if (!empty($versions)) {
                Cache::put($cacheKey, $versions, now()->addDay());
            }

            return $versions;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Modrinth game_version list error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search for modpacks on Modrinth.
     */
    public function search(string $query = '', int $page = 0, int $pageSize = 20, array $filters = []): array
    {
        // Facets are AND-ed groups; each inner array is an OR set. Modrinth folds
        // both mod loaders and content categories into the `categories` facet.
        $facets = [['project_type:modpack']];

        if (!empty($filters['gameVersion'])) {
            $facets[] = ['versions:' . $filters['gameVersion']];
        }
        $requestedLoaders = $this->requestedLoaders($filters);
        if (!empty($requestedLoaders)) {
            $facets[] = array_map(fn (string $loader) => 'categories:' . $loader, $requestedLoaders);
        }
        $facets[] = ['server_side:required', 'server_side:optional', 'server_side:unknown'];
        // Category applies only when the value is a Modrinth slug (Modrinth-provider
        // view). In the combined view the value is a numeric CurseForge category id,
        // which Modrinth can't map — so numeric values are intentionally skipped.
        if (!empty($filters['category']) && !is_numeric($filters['category'])) {
            $facets[] = ['categories:' . $filters['category']];
        }

        $response = $this->client->get('/search', [
            'query'  => $query,
            'facets' => json_encode($facets),
            'limit'  => $pageSize,
            'offset' => $page * $pageSize,
            'index'  => 'downloads',
        ]);

        if ($response->failed()) {
            Log::error('[ModpackManager] Modrinth search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Modrinth API request failed: ' . $response->status());
        }

        $hits = $response->json('hits', []);

        return array_map(fn (array $hit) => $this->normalizeHit($hit, 'modpack'), $hits);
    }

    /**
     * Search individual content (mods or plugins) by Modrinth project type.
     * Same request/normalisation as search() but keyed to `project_type:mod` or
     * `project_type:plugin`. The `loader` filter is folded into the `categories`
     * facet — which is where Modrinth keeps both mod loaders (forge/fabric/…) and
     * plugin platforms (paper/spigot/purpur/…), so it works for both types.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchByType(string $query = '', string $projectType = 'mod', int $page = 0, int $pageSize = 20, array $filters = []): array
    {
        $facets = [['project_type:' . $projectType]];

        if (!empty($filters['gameVersion'])) {
            $facets[] = ['versions:' . $filters['gameVersion']];
        }

        $allowedLoaders = $projectType === 'plugin'
            ? ['bukkit', 'spigot', 'paper', 'purpur', 'folia', 'sponge']
            : ['forge', 'neoforge', 'fabric', 'quilt'];
        $requested = $filters['loaders'] ?? (isset($filters['loader']) ? [$filters['loader']] : []);
        $requested = array_values(array_unique(array_filter(array_map(
            fn ($loader) => strtolower(trim((string) $loader)),
            is_array($requested) ? $requested : [$requested]
        ), fn ($loader) => in_array($loader, $allowedLoaders, true))));

        if (!empty($requested)) {
            $facets[] = array_map(fn (string $loader) => 'categories:' . $loader, $requested);
        }

        // Trust Modrinth's explicit environment metadata. Projects marked as
        // unsupported on dedicated servers are excluded; unknown remains visible.
        if ($projectType === 'mod') {
            $facets[] = ['server_side:required', 'server_side:optional', 'server_side:unknown'];
        }

        $categories = array_values(array_unique(array_filter(array_map(
            fn ($category) => strtolower(trim((string) $category)),
            is_array($filters['categories'] ?? null) ? $filters['categories'] : []
        ), fn ($category) => preg_match('/^[a-z0-9-]+$/', $category) === 1)));
        if (!empty($categories)) {
            $facets[] = array_map(fn (string $category) => 'categories:' . $category, $categories);
        }

        $response = $this->client->get('/search', [
            'query'  => $query,
            'facets' => json_encode($facets),
            'limit'  => $pageSize,
            'offset' => $page * $pageSize,
            'index'  => 'downloads',
        ]);

        if ($response->failed()) {
            Log::error('[ModpackManager] Modrinth content search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Modrinth API request failed: ' . $response->status());
        }

        return array_map(fn (array $hit) => $this->normalizeHit($hit, $projectType), $response->json('hits', []));
    }

    /**
     * Modpack categories from Modrinth, alphabetical — used to populate the
     * browser's category filter when Modrinth is the active provider. Cached a
     * day. Each entry is ['slug' => string, 'name' => string]; the slug is what
     * the search facet expects. Returns [] on failure; only non-empty results
     * are cached.
     *
     * @return array<int, array{slug:string, name:string}>
     */
    public function getCategories(): array
    {
        $cacheKey = 'modpack-manager:modrinth:categories';

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/tag/category');

            if ($response->failed()) {
                Log::warning('[ModpackManager] Modrinth categories failed', ['status' => $response->status()]);
                return [];
            }

            $categories = [];
            foreach ($response->json() ?? [] as $c) {
                // Modrinth folds loaders into the same tag list; keep only genuine
                // modpack content categories.
                if (($c['project_type'] ?? null) === 'modpack' && !empty($c['name'])) {
                    $slug = (string) $c['name'];
                    $categories[$slug] = [
                        'slug' => $slug,
                        'name' => ucwords(str_replace('-', ' ', $slug)),
                    ];
                }
            }

            $categories = array_values($categories);
            usort($categories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            if (!empty($categories)) {
                Cache::put($cacheKey, $categories, now()->addDay());
            }

            return $categories;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Modrinth categories error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a single project by ID or slug.
     */
    public function getProject(string $idOrSlug, string $projectType = 'modpack'): array
    {
        $response = $this->client->get("/project/{$idOrSlug}");

        if ($response->failed()) {
            throw new RuntimeException("Modrinth: could not fetch project {$idOrSlug}");
        }

        return $this->normalizeProject($response->json(), $projectType);
    }

    /**
     * Get versions for a project, filtered to server-compatible loaders.
     *
     * Only the newest 50 versions are kept, so a project with a long release
     * history can push a still-compatible older build off the end. Passing the
     * browser's active Minecraft version narrows the request server-side so
     * that build survives the cut.
     *
     * When the browser has an explicit loader filter, only versions tagged for
     * that exact loader are returned. This keeps the selected release consistent
     * with what the user asked to install.
     *
     * @param  array{gameVersion?:string, loader?:string}  $filters
     */
    public function getVersions(string $projectId, array $loaders = ['forge', 'fabric', 'quilt', 'neoforge'], array $filters = []): array
    {
        $requested = $filters['loaders'] ?? (isset($filters['loader']) ? [$filters['loader']] : []);
        $requested = array_values(array_unique(array_filter(array_map(
            fn ($loader) => strtolower(trim((string) $loader)),
            is_array($requested) ? $requested : [$requested]
        ))));
        $requestedLoaders = array_values(array_intersect($loaders, $requested));
        if (!empty($requestedLoaders)) {
            $loaders = $requestedLoaders;
        }

        $versions = $this->fetchVersions($projectId, $loaders, $filters);

        return array_values(array_map(fn (array $v) => [
            'id'           => $v['id'],
            'name'         => $v['name'],
            'versionNumber'=> $v['version_number'],
            'datePublished'=> $v['date_published'],
            'loaders'      => $v['loaders'] ?? [],
            'gameVersions' => $v['game_versions'] ?? [],
            'downloads'    => $v['downloads'] ?? 0,
            'prerelease'   => ($v['version_type'] ?? 'release') !== 'release',
            'files'        => array_map(fn (array $f) => [
                'url'      => $f['url'],
                'filename' => $f['filename'],
                'size'     => $f['size'],
                'primary'  => $f['primary'] ?? false,
                'sha1'     => $f['hashes']['sha1'] ?? null,
            ], $v['files'] ?? []),
        ], array_slice($versions, 0, 50)));
    }

    /**
     * One raw `/project/{id}/version` response, optionally narrowed by Minecraft
     * version. Modrinth expects both facets as JSON-encoded arrays.
     *
     * @param  array{gameVersion?:string}  $filters
     */
    private function fetchVersions(string $projectId, array $loaders, array $filters = []): array
    {
        $params = [
            'loaders' => json_encode($loaders),
        ];

        if (!empty($filters['gameVersion'])) {
            $params['game_versions'] = json_encode([$filters['gameVersion']]);
        }

        $response = $this->client->get("/project/{$projectId}/version", $params);

        if ($response->failed()) {
            throw new RuntimeException("Modrinth: could not fetch versions for project {$projectId}");
        }

        return $response->json([]);
    }

    /**
     * Get the primary download URL from a version's files list.
     */
    public function getPrimaryFileUrl(array $files): ?string
    {
        foreach ($files as $file) {
            if ($file['primary']) {
                return $file['url'];
            }
        }

        return $files[0]['url'] ?? null;
    }

    // ─── Normalise ────────────────────────────────────────────────────────────

    private function normalizeHit(array $hit, string $projectType = 'modpack'): array
    {
        return [
            'provider'      => 'modrinth',
            'id'            => $hit['project_id'],
            'slug'          => $hit['slug'],
            'name'          => $hit['title'],
            'summary'       => $hit['description'] ?? '',
            'description'   => $hit['description'] ?? '',
            'downloadCount' => $hit['downloads'] ?? 0,
            'iconUrl'       => $hit['icon_url'] ?? null,
            'author'        => $hit['author'] ?? 'Unknown',
            'dateModified'  => $hit['date_modified'] ?? null,
            'gameVersions'  => implode(', ', array_slice($hit['versions'] ?? [], 0, 3)),
            // Search hits fold loaders into `categories` alongside content tags.
            'loaders'       => $this->extractLoaders($hit['categories'] ?? []),
            'latestFileId'  => null,
            'websiteUrl'    => !empty($hit['slug']) ? 'https://modrinth.com/' . ($projectType === 'plugin' ? 'plugin' : ($projectType === 'mod' ? 'mod' : 'modpack')) . '/' . rawurlencode((string) $hit['slug']) : null,
            'gallery'       => $this->normalizeGallery($hit['gallery'] ?? []),
        ];
    }

    private function normalizeProject(array $project, string $projectType = 'modpack'): array
    {
        return [
            'provider'      => 'modrinth',
            'id'            => $project['id'],
            'slug'          => $project['slug'],
            'name'          => $project['title'],
            'summary'       => $project['description'] ?? '',
            'description'   => $project['body'] ?? $project['description'] ?? '',
            'downloadCount' => $project['downloads'] ?? 0,
            'iconUrl'       => $project['icon_url'] ?? null,
            'author'        => $project['team'] ?? 'Unknown',
            'dateModified'  => $project['updated'] ?? null,
            'gameVersions'  => null,
            // Projects expose a dedicated `loaders` array; fall back to categories.
            'loaders'       => $this->extractLoaders($project['loaders'] ?? $project['categories'] ?? []),
            'latestFileId'  => null,
            'websiteUrl'    => !empty($project['slug']) ? 'https://modrinth.com/' . ($projectType === 'plugin' ? 'plugin' : ($projectType === 'mod' ? 'mod' : 'modpack')) . '/' . rawurlencode((string) $project['slug']) : null,
            'gallery'       => $this->normalizeGallery($project['gallery'] ?? []),
        ];
    }

    private function requestedLoaders(array $filters): array
    {
        $values = $filters['loaders'] ?? (isset($filters['loader']) ? [$filters['loader']] : []);

        return array_values(array_unique(array_filter(array_map(
            fn ($loader) => strtolower(trim((string) $loader)),
            is_array($values) ? $values : [$values]
        ), fn ($loader) => in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true))));
    }

    private function normalizeGallery(array $gallery): array
    {
        $images = [];

        foreach ($gallery as $item) {
            if (is_string($item)) {
                $url = $item;
                $title = null;
                $description = null;
            } elseif (is_array($item)) {
                $url = $item['url'] ?? null;
                $title = $item['title'] ?? null;
                $description = $item['description'] ?? null;
            } else {
                continue;
            }

            if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $images[] = [
                'url' => $url,
                'thumbnailUrl' => $url,
                'title' => $title,
                'description' => $description,
            ];
        }

        return $images;
    }

    /**
     * Filter a Modrinth tag list down to the mod loaders and present them as
     * display names (e.g. 'neoforge' → 'NeoForge').
     *
     * @return string[]
     */
    private function extractLoaders(array $tags): array
    {
        static $map = [
            'forge'       => 'Forge',
            'neoforge'    => 'NeoForge',
            'fabric'      => 'Fabric',
            'quilt'       => 'Quilt',
            'liteloader'  => 'LiteLoader',
            'bukkit'      => 'Bukkit',
            'spigot'      => 'Spigot',
            'paper'       => 'Paper',
            'purpur'      => 'Purpur',
            'folia'       => 'Folia',
            'sponge'      => 'Sponge',
        ];

        $loaders = [];
        foreach ($tags as $tag) {
            $key = strtolower((string) $tag);
            if (isset($map[$key])) {
                $loaders[$map[$key]] = true;
            }
        }

        return array_keys($loaders);
    }
}
