<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * SpigotMC resources via the Spiget mirror API (api.spiget.org).
 *
 * SpigotMC itself sits behind Cloudflare and refuses non-browser downloads, so
 * only what Spiget mirrors can be installed: the LATEST file of free resources
 * hosted on SpigotMC, plus external resources whose link is a direct .jar.
 * Premium resources are never returned.
 */
class SpigetService
{
    private const BASE_URL = 'https://api.spiget.org/v2';
    private const SITE_URL = 'https://www.spigotmc.org';

    private const LIST_FIELDS = 'id,name,tag,testedVersions,icon.url,author,downloads,updateDate,releaseDate,premium,external,file,version';

    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::withHeaders([
            'User-Agent' => 'pelican-modpack-manager/1.7.1 (+https://github.com/cosmii02/pelican-modpackmanager)',
            'Accept'     => 'application/json',
        ])->baseUrl(self::BASE_URL)->timeout(20);
    }

    /**
     * Spiget has no Minecraft-version or platform facet, so filtering happens on
     * the caller side; an empty query lists the most-downloaded free resources.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query = '', int $page = 0, int $pageSize = 20): array
    {
        $params = [
            'size'   => $pageSize,
            'page'   => $page + 1,
            'sort'   => '-downloads',
            'fields' => self::LIST_FIELDS,
        ];

        $query = trim($query);
        if ($query === '') {
            $response = $this->client->get('/resources/free', $params);
        } else {
            $response = $this->client->get('/search/resources/' . rawurlencode($query), $params + ['field' => 'name']);
        }

        // Spiget answers 404 for a search with no hits.
        if ($response->status() === 404) {
            return [];
        }
        if ($response->failed()) {
            Log::error('[ModpackManager] Spiget search failed', ['status' => $response->status()]);
            throw new RuntimeException('Spiget API request failed: ' . $response->status());
        }

        $resources = array_values(array_filter(
            $response->json() ?? [],
            fn ($r) => is_array($r) && empty($r['premium'])
        ));

        $authors = $this->authorNames(array_map(fn ($r) => (int) ($r['author']['id'] ?? 0), $resources));

        return array_map(fn (array $r) => $this->normalize($r, $authors), $resources);
    }

    public function getResource(int $id): array
    {
        $response = $this->client->get("/resources/{$id}");

        if ($response->failed()) {
            throw new RuntimeException("Spiget: could not fetch resource {$id}");
        }

        $resource = $response->json();
        if (!empty($resource['premium'])) {
            throw new RuntimeException('Premium SpigotMC resources cannot be installed.');
        }

        $item = $this->normalize($resource, $this->authorNames([(int) ($resource['author']['id'] ?? 0)]));

        $html = base64_decode((string) ($resource['description'] ?? ''), true);
        if (is_string($html) && $html !== '') {
            $item['description'] = $this->htmlToText($html);
        }

        return $item;
    }

    /**
     * The installable file for a resource. Spiget only mirrors the latest upload,
     * so this is at most one entry; [] when nothing can be fetched without a browser.
     *
     * @param  array{gameVersion?:string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getVersions(int $id, array $filters = []): array
    {
        $resource = $this->client->get("/resources/{$id}", ['fields' => self::LIST_FIELDS]);
        if ($resource->failed()) {
            throw new RuntimeException("Spiget: could not fetch resource {$id}");
        }
        $resource = $resource->json();

        if (!empty($resource['premium'])) {
            return [];
        }

        [$url, $fileName] = $this->downloadSource($resource);
        if ($url === null) {
            return [];
        }

        $latest = $this->client->get("/resources/{$id}/versions/latest");
        $versionName = $latest->successful() ? (string) ($latest->json('name') ?? '') : '';
        $released = $latest->successful() ? $latest->json('releaseDate') : ($resource['updateDate'] ?? null);

        $fileName ??= $this->jarName((string) ($resource['name'] ?? "spigot-{$id}"), $versionName);

        return [[
            'id'            => (string) ($resource['version']['id'] ?? $id),
            'name'          => $versionName !== '' ? $versionName : 'Latest',
            'versionNumber' => $versionName !== '' ? $versionName : 'Latest',
            'datePublished' => is_numeric($released) ? gmdate('c', (int) $released) : null,
            'loaders'       => ['spigot', 'bukkit', 'paper', 'purpur'],
            'gameVersions'  => $this->gameVersionsFor($resource['testedVersions'] ?? [], $filters['gameVersion'] ?? ''),
            'files'         => [[
                'url'      => $url,
                'filename' => $fileName,
                'primary'  => true,
            ]],
        ]];
    }

    /**
     * Turn Spiget's download endpoint into the CDN URL it redirects to, so Wings
     * gets a direct link. Other URLs are returned unchanged.
     */
    public function resolveDownloadUrl(string $url): string
    {
        if (!str_starts_with($url, self::BASE_URL . '/')) {
            return $url;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'pelican-modpack-manager/1.7.1 (+https://github.com/cosmii02/pelican-modpackmanager)'])
                ->withoutRedirecting()
                ->timeout(20)
                ->get($url);

            $location = $response->header('Location');
            if ($response->redirect() && is_string($location) && preg_match('#^https://#i', $location)) {
                return $location;
            }
        } catch (Throwable $e) {
            Log::info('[ModpackManager] Spiget download redirect lookup failed', ['error' => $e->getMessage()]);
        }

        return $url;
    }

    /**
     * Whether a resource has a file this panel can fetch, and from where.
     *
     * @return array{0:?string, 1:?string}  [url, fileName or null to derive one]
     */
    private function downloadSource(array $resource): array
    {
        $id = (int) ($resource['id'] ?? 0);
        $file = $resource['file'] ?? [];

        if (empty($resource['external'])) {
            return strtolower((string) ($file['type'] ?? '')) === '.jar'
                ? [self::BASE_URL . "/resources/{$id}/download", null]
                : [null, null];
        }

        $external = (string) ($file['externalUrl'] ?? '');
        $path = (string) parse_url($external, PHP_URL_PATH);
        if (preg_match('#^https://#i', $external) && preg_match('/\.jar$/i', $path)) {
            return [$external, basename(rawurldecode($path))];
        }

        return [null, null];
    }

    /** True when the resource can be installed by this panel. */
    public function isInstallable(array $resource): bool
    {
        return empty($resource['premium']) && $this->downloadSource($resource)[0] !== null;
    }

    /**
     * Spigot lists tested Minecraft lines ("1.21"), not patch releases. Treat a
     * tested line as covering the server's patch release so the drawer's
     * compatibility hint matches.
     */
    private function gameVersionsFor(array $tested, string $serverVersion): array
    {
        $tested = array_values(array_filter(array_map('strval', $tested)));

        if ($serverVersion !== '' && !in_array($serverVersion, $tested, true)) {
            $line = implode('.', array_slice(explode('.', $serverVersion), 0, 2));
            if (in_array($line, $tested, true)) {
                $tested[] = $serverVersion;
            }
        }

        return $tested;
    }

    /**
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function authorNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $names = [];
        $missing = [];

        foreach ($ids as $id) {
            $cached = Cache::get("modpack-manager:spiget:author:{$id}");
            if (is_string($cached)) {
                $names[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if (empty($missing)) {
            return $names;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $id) => $pool->as((string) $id)
                    ->withHeaders(['User-Agent' => 'pelican-modpack-manager/1.7.1 (+https://github.com/cosmii02/pelican-modpackmanager)', 'Accept' => 'application/json'])
                    ->timeout(10)
                    ->get(self::BASE_URL . "/authors/{$id}", ['fields' => 'id,name']),
                $missing
            ));
        } catch (Throwable $e) {
            Log::info('[ModpackManager] Spiget author lookup failed', ['error' => $e->getMessage()]);
            return $names;
        }

        foreach ($missing as $id) {
            $response = $responses[(string) $id] ?? null;
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                $name = trim((string) $response->json('name'));
                if ($name !== '') {
                    $names[$id] = $name;
                    Cache::put("modpack-manager:spiget:author:{$id}", $name, now()->addWeek());
                }
            }
        }

        return $names;
    }

    private function normalize(array $r, array $authors): array
    {
        $id = (int) ($r['id'] ?? 0);
        $icon = (string) ($r['icon']['url'] ?? '');
        $tested = array_values(array_filter(array_map('strval', $r['testedVersions'] ?? [])));
        usort($tested, fn ($a, $b) => version_compare($b, $a));

        return [
            'provider'      => 'spigot',
            'id'            => $id,
            'slug'          => (string) $id,
            'name'          => (string) ($r['name'] ?? "Resource {$id}"),
            'summary'       => (string) ($r['tag'] ?? ''),
            'description'   => (string) ($r['tag'] ?? ''),
            'downloadCount' => (int) ($r['downloads'] ?? 0),
            'iconUrl'       => $icon !== '' ? self::SITE_URL . '/' . ltrim($icon, '/') : null,
            'author'        => $authors[(int) ($r['author']['id'] ?? 0)] ?? '',
            'dateModified'  => isset($r['updateDate']) ? gmdate('c', (int) $r['updateDate']) : null,
            'gameVersions'  => implode(', ', array_slice($tested, 0, 3)),
            'loaders'       => ['Spigot'],
            'latestFileId'  => isset($r['version']['id']) ? (string) $r['version']['id'] : null,
            'websiteUrl'    => self::SITE_URL . '/resources/' . $id . '/',
            'gallery'       => [],
            'installable'   => $this->isInstallable($r),
        ];
    }

    private function jarName(string $name, string $version): string
    {
        $base = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '', '-.');
        $ver = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $version) ?? '', '-.');

        return ($base !== '' ? $base : 'plugin') . ($ver !== '' ? '-' . $ver : '') . '.jar';
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{200B}]+/u", ' ', $text) ?? $text;

        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }
}
