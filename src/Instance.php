<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * One VuFind installation the GUI can edit.
 *
 * Originals are read from $home, all writes go to $local (VuFind's
 * VUFIND_LOCAL_DIR). The originals can and should be mounted read-only.
 */
final class Instance
{
    /**
     * @param string[]                                                      $protectedFiles relative paths whose local copy must not be removed
     * @param array{endpoint: string, public_url: string, parked_key: string}|null $mcp
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $home,
        public readonly string $local,
        public readonly string $url,
        public readonly ?string $solr,
        public readonly string $backupDir,
        public readonly array $protectedFiles,
        public readonly ?array $mcp,
    ) {
    }

    /** @param array<string, mixed> $c */
    public static function fromArray(string $key, array $c): self
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
            throw new \InvalidArgumentException("Instance key '$key' may only contain a-z, 0-9, _ and -");
        }
        $home = rtrim((string)($c['home'] ?? ''), '/');
        if ($home === '') {
            throw new \InvalidArgumentException("Instance '$key': 'home' (VUFIND_HOME) is required");
        }
        $local = rtrim((string)($c['local'] ?? $home . '/local'), '/');
        $mcp = null;
        if (!empty($c['mcp']['endpoint'])) {
            $mcp = [
                'endpoint' => (string)$c['mcp']['endpoint'],
                // URL a client outside the GUI would use; its host is sent as Host header
                'public_url' => (string)($c['mcp']['public_url'] ?? $c['mcp']['endpoint']),
                'parked_key' => (string)($c['mcp']['parked_key'] ?? 'GuiDisabled'),
            ];
        }
        return new self(
            key: $key,
            label: (string)($c['label'] ?? $key),
            home: $home,
            local: $local,
            url: rtrim((string)($c['url'] ?? ''), '/'),
            solr: isset($c['solr']) && $c['solr'] !== '' ? rtrim((string)$c['solr'], '/') : null,
            backupDir: rtrim((string)($c['backup_dir'] ?? $local . '/gui-backups'), '/'),
            protectedFiles: array_values((array)($c['protected_files'] ?? ['config/vufind/config.ini'])),
            mcp: $mcp,
        );
    }

    public function origPath(string $rel): string
    {
        return $this->home . '/' . $rel;
    }

    public function localPath(string $rel): string
    {
        return $this->local . '/' . $rel;
    }

    /** Problems that make the instance unusable, as translation keys with parameters */
    public function problems(): array
    {
        $p = [];
        if (!is_dir($this->home . '/config/vufind')) {
            $p[] = ['error.no_vufind_home', ['path' => $this->home]];
        }
        if (!is_dir($this->local)) {
            $p[] = ['error.no_local_dir', ['path' => $this->local]];
        } elseif (!is_writable($this->local)) {
            $p[] = ['error.local_not_writable', ['path' => $this->local]];
        }
        return $p;
    }
}
