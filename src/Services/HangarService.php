<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PaperMC's Hangar (hangar.papermc.io). Only the PAPER platform is used: Purpur
 * runs Paper plugins, Folia support is a project tag, and Spigot/Bukkit/Sponge
 * cannot load Paper-targeted plugins.
 */
class HangarService
{
    private const BASE_URL = 'https://hangar.papermc.io/api/v1';
    private const SITE_URL = 'https://hangar.papermc.io';

    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::withHeaders([
            'User-Agent' => 'pelican-modpack-manager',
            'Accept'     => 'application/json',
        ])->baseUrl(self::BASE_URL)->timeout(20);
    }

    /**
     * @param  array{gameVersion?:string, foliaOnly?:bool}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query = '', int $page = 0, int $pageSize = 20, array $filters = []): array
    {
        $params = [
            'limit'    => $pageSize,
            'offset'   => $page * $pageSize,
            'sort'     => '-downloads',
            'platform' => 'PAPER',
        ];

        if (trim($query) !== '') {
            $params['q'] = trim($query);
        }
        if (!empty($filters['gameVersion'])) {
            $params['version'] = $filters['gameVersion'];
        }
        if (!empty($filters['foliaOnly'])) {
            $params['tag'] = 'SUPPORTS_FOLIA';
        }

        $response = $this->client->get('/projects', $params);

        if ($response->failed()) {
            Log::error('[ModpackManager] Hangar search failed', ['status' => $response->status()]);
            throw new RuntimeException('Hangar API request failed: ' . $response->status());
        }

        return array_map(fn (array $p) => $this->normalize($p), $response->json('result', []));
    }

    public function getProject(string $slug): array
    {
        $response = $this->client->get('/projects/' . rawurlencode($slug));

        if ($response->failed()) {
            throw new RuntimeException("Hangar: could not fetch project {$slug}");
        }

        $project = $response->json();
        $item = $this->normalize($project);
        $body = trim((string) ($project['mainPageContent'] ?? ''));
        if ($body !== '') {
            $item['description'] = $body;
        }

        return $item;
    }

    /**
     * Newest-first Paper builds of a project that carry a downloadable jar.
     * Versions published only as an external release page are skipped. When the
     * server's exact Minecraft version matches nothing, all builds are listed and
     * the drawer's compatibility hint warns instead.
     *
     * @param  array{gameVersion?:string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getVersions(string $slug, array $filters = []): array
    {
        $versions = [];
        if (!empty($filters['gameVersion'])) {
            $versions = $this->fetchVersions($slug, $filters['gameVersion']);
        }
        if (empty($versions)) {
            $versions = $this->fetchVersions($slug, null);
        }

        return $versions;
    }

    private function fetchVersions(string $slug, ?string $gameVersion): array
    {
        $params = ['limit' => 25, 'offset' => 0, 'platform' => 'PAPER'];
        if ($gameVersion !== null) {
            $params['platformVersion'] = $gameVersion;
        }

        $response = $this->client->get('/projects/' . rawurlencode($slug) . '/versions', $params);

        if ($response->failed()) {
            throw new RuntimeException("Hangar: could not fetch versions for {$slug}");
        }

        $out = [];
        foreach ($response->json('result', []) as $v) {
            $download = $v['downloads']['PAPER'] ?? null;
            [$url, $fileName] = $this->fileFor(is_array($download) ? $download : []);
            if ($url === null) {
                continue;
            }

            $channel = (string) ($v['channel']['name'] ?? '');
            $name = (string) ($v['name'] ?? '');

            $out[] = [
                'id'            => (string) ($v['id'] ?? $name),
                'name'          => $name,
                'versionNumber' => $name,
                'displayName'   => $channel !== '' && strcasecmp($channel, 'Release') !== 0 ? "{$name} ({$channel})" : $name,
                'prerelease'    => $channel !== '' && strcasecmp($channel, 'Release') !== 0,
                'datePublished' => $v['createdAt'] ?? null,
                'loaders'       => ['paper', 'purpur'],
                'gameVersions'  => array_values(array_map('strval', $v['platformDependencies']['PAPER'] ?? [])),
                'files'         => [[
                    'url'      => $url,
                    'filename' => $fileName,
                    'size'     => $download['fileInfo']['sizeBytes'] ?? null,
                    'primary'  => true,
                ]],
            ];
        }

        return $out;
    }

    /** @return array{0:?string, 1:?string} */
    private function fileFor(array $download): array
    {
        $url = (string) ($download['downloadUrl'] ?? '');
        $name = (string) ($download['fileInfo']['name'] ?? '');

        if ($url !== '' && $name !== '') {
            return [$url, $name];
        }

        $external = (string) ($download['externalUrl'] ?? '');
        $path = (string) parse_url($external, PHP_URL_PATH);
        if (preg_match('#^https://#i', $external) && preg_match('/\.jar$/i', $path)) {
            return [$external, basename(rawurldecode($path))];
        }

        return [null, null];
    }

    private function normalize(array $p): array
    {
        $owner = (string) ($p['namespace']['owner'] ?? '');
        $slug = (string) ($p['namespace']['slug'] ?? $p['name'] ?? '');

        $paper = array_values(array_map('strval', $p['supportedPlatforms']['PAPER'] ?? []));
        usort($paper, fn ($a, $b) => version_compare($b, $a));

        $loaders = [];
        foreach (array_keys($p['supportedPlatforms'] ?? []) as $platform) {
            $loaders[] = ucfirst(strtolower((string) $platform));
        }
        if (in_array('SUPPORTS_FOLIA', $p['settings']['tags'] ?? [], true)) {
            $loaders[] = 'Folia';
        }

        return [
            'provider'      => 'hangar',
            'id'            => $slug,
            'slug'          => $slug,
            'name'          => (string) ($p['name'] ?? $slug),
            'summary'       => (string) ($p['description'] ?? ''),
            'description'   => (string) ($p['description'] ?? ''),
            'downloadCount' => (int) ($p['stats']['downloads'] ?? 0),
            'iconUrl'       => $p['avatarUrl'] ?? null,
            'author'        => $owner,
            'dateModified'  => $p['lastUpdated'] ?? null,
            'gameVersions'  => implode(', ', array_slice($paper, 0, 3)),
            'loaders'       => $loaders,
            'latestFileId'  => null,
            'websiteUrl'    => $owner !== '' && $slug !== '' ? self::SITE_URL . '/' . rawurlencode($owner) . '/' . rawurlencode($slug) : null,
            'gallery'       => [],
        ];
    }
}
