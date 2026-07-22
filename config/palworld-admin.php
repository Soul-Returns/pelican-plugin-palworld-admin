<?php

return [
    /*
     * How the plugin finds a server's Palworld REST API.
     *
     * host:  null = use the server's node FQDN. Set to an IP/hostname to override globally.
     * port:  taken from the server's REST_API_PORT egg variable when present, otherwise this default.
     * password: taken from the server's ADMIN_PASSWORD egg variable.
     */
    'host' => env('PALWORLD_ADMIN_HOST'),
    'default_port' => (int) env('PALWORLD_ADMIN_DEFAULT_PORT', 8212),

    'variables' => [
        'admin_password' => 'ADMIN_PASSWORD',
        'rest_api_port' => 'REST_API_PORT',
    ],

    /*
     * Plugin pages only show up for servers whose egg name matches one of these
     * (case-insensitive substring match), so the tab doesn't appear on non-Palworld servers.
     */
    'egg_name_matches' => ['palworld'],

    'http_timeout' => (int) env('PALWORLD_ADMIN_HTTP_TIMEOUT', 5),

    /*
     * Where the game maintains its local ban list (relative to the server
     * root). Read via Wings; each line is "userId,playerUid".
     */
    'ban_list_path' => 'Pal/Saved/SaveGames/banlist.txt',
];
