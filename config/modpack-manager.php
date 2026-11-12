<?php

return [
    'curseforge_api_key' => env('MODPACK_MANAGER_CURSEFORGE_API_KEY', ''),
    'modrinth_token'     => env('MODPACK_MANAGER_MODRINTH_TOKEN', ''),

    // The Modpacks page only shows on servers whose egg has one of these tags
    // (case-insensitive). Default: minecraft. An empty list disables the filter
    // and shows the page on every server. Edited via the plugin settings UI,
    // persisted as a comma-separated MODPACK_MANAGER_EGG_TAGS env value.
    'required_egg_tags' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('MODPACK_MANAGER_EGG_TAGS', 'minecraft'))),
        fn ($t) => $t !== ''
    )),

    // Files always preserved during a modpack update
    'preserved_files' => [
        'eula.txt',
        'ops.json',
        'whitelist.json',
        'banned-players.json',
        'banned-ips.json',
        'usercache.json',
    ],

    // Operational server.properties values that may safely carry across installs.
    // World generation, datapack selection, seed, level type, generator settings,
    // resource-pack settings, and other pack-owned values intentionally are not here.
    'preserved_server_properties' => [
        'server-ip',
        'server-port',
        'query.port',
        'rcon.port',
        'rcon.password',
        'enable-query',
        'enable-rcon',
        'motd',
        'max-players',
        'online-mode',
        'white-list',
        'enforce-whitelist',
        'enable-status',
        'hide-online-players',
        'broadcast-console-to-ops',
        'broadcast-rcon-to-ops',
        'op-permission-level',
        'function-permission-level',
        'difficulty',
        'gamemode',
        'force-gamemode',
        'hardcore',
        'pvp',
        'allow-flight',
        'enable-command-block',
        'spawn-protection',
        'view-distance',
        'simulation-distance',
        'player-idle-timeout',
        'max-tick-time',
    ],

    // When enabled, the installer writes a small .modpack-manager.json file into
    // each server's own files after a successful install, and the Modpacks page
    // reads it to recover the "currently installed" pack when no database record
    // exists (e.g. records lost on a plugin re-install). Disabled by default.
    'store_metadata' => filter_var(env('MODPACK_MANAGER_STORE_METADATA', false), FILTER_VALIDATE_BOOLEAN),

    // Sort weight for the "Modpacks" entry in the server-panel sidebar. Lower
    // numbers appear higher in the list. Edited via the plugin settings UI and
    // persisted as MODPACK_MANAGER_NAV_SORT.
    'navigation_sort' => (int) env('MODPACK_MANAGER_NAV_SORT', 50),

    // Max download size in MB before job times out
    'download_timeout' => 300,

    // How many files Wings pulls onto the server at once during install. Each
    // file is fetched by Wings directly from its source (e.g. CurseForge's CDN),
    // so this caps both Wings' simultaneous remote downloads and concurrent
    // CurseForge fetches. Wings rejects extra simultaneous downloads past its own
    // limit (3 by default); raise this only if your Wings config and API limits allow.
    'remote_download_concurrency' => max(1, (int) env('MODPACK_MANAGER_REMOTE_DOWNLOAD_CONCURRENCY', 3)),

    // FTB's dist.modpacks.ch downloads (and gzipped text files from
    // files.feed-the-beast.com) do not provide Content-Length, so Wings
    // cannot pull them directly. The Panel fallback downloads a small bounded
    // batch concurrently, then writes each completed file to Wings. Four is a
    // conservative default; advanced users can raise it to at most 8.
    'panel_fallback_concurrency' => max(1, min(8, (int) env('MODPACK_MANAGER_PANEL_FALLBACK_CONCURRENCY', 4))),

    // ─── Scheduled update checks ───────────────────────────────────────────────
    // Periodically check installed modpacks for newer versions and notify the
    // server owner (panel bell) when a new version first appears.
    'update_checks_enabled' => env('MODPACK_MANAGER_UPDATE_CHECKS', true),

    // How often the scheduler runs the check. One of:
    //   'hourly' | 'every_six_hours' | 'twice_daily' | 'daily' | 'weekly'
    'update_check_frequency' => env('MODPACK_MANAGER_UPDATE_FREQUENCY', 'daily'),
];
