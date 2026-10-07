<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * GUI settings: instances, language and access protection.
 *
 * Read from the PHP file named in VUFIND_CONFIG_GUI_CONFIG, else
 * config/config.php. Without a file, a single instance is built from
 * VuFind's own environment variables (VUFIND_HOME, VUFIND_LOCAL_DIR).
 */
final class Settings
{
    /**
     * @param array<string, Instance>                           $instances
     * @param string[]                                          $allowedHosts
     * @param array{user: string, password_hash: string}|null   $auth
     */
    public function __construct(
        public readonly array $instances,
        public readonly string $language,
        public readonly array $allowedHosts,
        public readonly ?array $auth,
        public readonly ?string $source,
    ) {
        if (!$instances) {
            throw new \InvalidArgumentException('No VuFind instance configured');
        }
    }

    public static function load(?string $path = null): self
    {
        $path ??= getenv('VUFIND_CONFIG_GUI_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
        if (is_file($path)) {
            $c = require $path;
            if (!is_array($c)) {
                throw new \InvalidArgumentException("$path must return an array");
            }
            return self::fromArray($c, $path);
        }
        return self::fromEnvironment();
    }

    /** @param array<string, mixed> $c */
    public static function fromArray(array $c, ?string $source = null): self
    {
        $instances = [];
        foreach ((array)($c['instances'] ?? []) as $key => $ic) {
            $instances[(string)$key] = Instance::fromArray((string)$key, (array)$ic);
        }
        $auth = null;
        if (!empty($c['auth']['user']) && !empty($c['auth']['password_hash'])) {
            $auth = ['user' => (string)$c['auth']['user'], 'password_hash' => (string)$c['auth']['password_hash']];
        }
        return new self(
            instances: $instances,
            language: (string)($c['language'] ?? 'auto'),
            allowedHosts: array_map('strtolower', (array)($c['allowed_hosts'] ?? ['localhost', '127.0.0.1', '[::1]'])),
            auth: $auth,
            source: $source,
        );
    }

    public static function fromEnvironment(): self
    {
        $home = getenv('VUFIND_HOME') ?: '/usr/local/vufind';
        $env = fn (string $name, ?string $default = null) => getenv($name) ?: $default;
        return self::fromArray([
            'language' => $env('VUFIND_CONFIG_GUI_LANG', 'auto'),
            'allowed_hosts' => array_filter(explode(',', $env('VUFIND_CONFIG_GUI_ALLOWED_HOSTS', 'localhost,127.0.0.1,[::1]'))),
            'instances' => ['default' => [
                'label' => 'VuFind',
                'home' => $home,
                'local' => $env('VUFIND_LOCAL_DIR', $home . '/local'),
                'url' => $env('VUFIND_URL', ''),
                'solr' => $env('VUFIND_SOLR_URL', 'http://localhost:8983/solr/biblio'),
            ]],
        ]);
    }

    public function instance(?string $key): Instance
    {
        // array_key_first(), not reset(): reset() moves the pointer of a readonly array
        return $this->instances[$key ?? ''] ?? $this->instances[array_key_first($this->instances)];
    }
}
