<?php

/**
 * vufind-config-gui configuration.
 *
 * Copy to config/config.php (or point VUFIND_CONFIG_GUI_CONFIG to a file
 * elsewhere). Without any config file, a single instance is built from the
 * environment variables VUFIND_HOME, VUFIND_LOCAL_DIR, VUFIND_URL and
 * VUFIND_SOLR_URL.
 *
 * SECURITY: this GUI can change every setting of VuFind, including database
 * and ILS credentials. Run it on 127.0.0.1 only, or behind an authenticating
 * reverse proxy / VPN. Read SECURITY.md before exposing it anywhere.
 */

return [
    // 'auto' follows the browser; 'en' or 'de' fixes the default.
    // Users can still switch in the header.
    'language' => 'auto',

    // Host names the GUI answers to (port is ignored). Protects against DNS
    // rebinding. Add your host name if you put the GUI behind a proxy.
    'allowed_hosts' => ['localhost', '127.0.0.1', '[::1]'],

    // Optional HTTP Basic authentication. Create the hash with:
    //   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
    // Only use it over HTTPS or on localhost.
    'auth' => null,
    // 'auth' => ['user' => 'admin', 'password_hash' => '$2y$10$...'],

    'instances' => [
        'main' => [
            'label' => 'VuFind',
            // VUFIND_HOME: originals are read from here (may be mounted read-only)
            'home' => '/usr/local/vufind',
            // VUFIND_LOCAL_DIR: all writes go here
            'local' => '/usr/local/vufind/local',
            // Public URL of VuFind, for the test search and record links (optional)
            'url' => 'http://localhost/vufind',
            // Solr core as seen from the GUI, for the ranking preview and filter checks (optional)
            'solr' => 'http://localhost:8983/solr/biblio',
            // Default: <local>/gui-backups
            // 'backup_dir' => '/var/backups/vufind-config-gui',
            // Local files the GUI must not delete (VuFind needs them to run)
            'protected_files' => ['config/vufind/config.ini'],
        ],

        // A second instance, e.g. a test installation. The GUI shows a switcher.
        // 'test' => [
        //     'label' => 'VuFind test',
        //     'home' => '/usr/local/vufind-test',
        //     'local' => '/usr/local/vufind-test/local',
        //     'url' => 'http://localhost:8080/vufind',
        //     'solr' => 'http://localhost:8983/solr/biblio',
        //
        //     // EXPERIMENTAL: MCP server from VuFind pull request #4939 (not merged).
        //     // Enables the MCP editor and the MCP test.
        //     'mcp' => [
        //         // URL the GUI uses to reach the endpoint
        //         'endpoint' => 'http://localhost:8080/vufind/api/v1/mcp',
        //         // URL clients use; its host:port is sent as Host header, because
        //         // the MCP SDK only accepts localhost hosts (DNS rebinding protection)
        //         'public_url' => 'http://localhost:8080/vufind/api/v1/mcp',
        //         // Key under which the GUI parks disabled tools (ignored by VuFind)
        //         'parked_key' => 'GuiDisabled',
        //     ],
        // ],
    ],
];
