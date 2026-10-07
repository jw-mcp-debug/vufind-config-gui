<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * File access for one instance: path checks, local copies, backups,
 * conflict detection and cache clearing.
 */
final class FileStore
{
    /**
     * Files shown prominently, grouped: [group key, label key, effect].
     * Effect: immediate | reconnect | reindex.
     */
    private const CURATED = [
        'config/vufind/searchspecs.yaml' => ['group.search', 'file.searchspecs', 'immediate'],
        'config/vufind/searches.ini' => ['group.search', 'file.searches', 'immediate'],
        'config/vufind/facets.ini' => ['group.facets', 'file.facets', 'immediate'],
        'config/vufind/config.ini' => ['group.display', 'file.config', 'immediate'],
        'config/vufind/RecordDataFormatter/DefaultRecord.ini' => ['group.display', 'file.recorddataformatter', 'immediate'],
        'config/vufind/RecordTabs.ini' => ['group.display', 'file.recordtabs', 'immediate'],
        'import/marc_local.properties' => ['group.index', 'file.marc_local', 'reindex'],
        'import/marc.properties' => ['group.index', 'file.marc', 'reindex'],
        'config/vufind/permissions.ini' => ['group.access', 'file.permissions', 'immediate'],
    ];

    private const CURATED_MCP = [
        'config/vufind/ModelContextProtocol.yaml' => ['group.mcp', 'file.mcp', 'reconnect'],
        'config/vufind/RateLimiter.yaml' => ['group.mcp', 'file.ratelimiter', 'immediate'],
    ];

    /** VuFind cache directories that hold parsed configuration */
    private const CACHE_DIRS = ['configs', 'searchspecs', 'yamls', 'objects'];

    public function __construct(private readonly Instance $inst)
    {
    }

    /** Only config/vufind/** and import/** with known extensions, no traversal */
    public static function checkRel(string $rel): string
    {
        if (
            !preg_match('#^(config/vufind|import)/[A-Za-z0-9_./-]+\.(ini|yaml|properties)$#', $rel)
            || str_contains($rel, '..') || str_contains($rel, '//')
        ) {
            throw new HttpException('error.invalid_path');
        }
        return $rel;
    }

    public static function type(string $rel): string
    {
        return pathinfo($rel, PATHINFO_EXTENSION);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public function curated(): array
    {
        $list = $this->inst->mcp ? self::CURATED_MCP + self::CURATED : self::CURATED;
        // The ILS driver's own file (e.g. Folio.ini) belongs next to the permissions
        $driver = $this->catalogDriver();
        if ($driver !== null) {
            $rel = "config/vufind/$driver.ini";
            if (is_file($this->inst->origPath($rel)) || is_file($this->inst->localPath($rel))) {
                $list[$rel] = ['group.access', 'file.ils_driver', 'immediate'];
            }
        }
        return $list;
    }

    /** [Catalog] driver from the effective config.ini, if it names a driver file */
    private function catalogDriver(): ?string
    {
        foreach (LineConfig::parse(LineConfig::readLines($this->effectivePath('config/vufind/config.ini')), 'ini') as $s) {
            if ($s['name'] !== 'Catalog') {
                continue;
            }
            foreach ($s['entries'] as $e) {
                if ($e['key'] === 'driver' && $e['active'] && preg_match('/^[A-Za-z0-9_]+$/', $e['value'])
                    && !in_array($e['value'], ['NoILS', 'Demo', 'Sample'], true)) {
                    return $e['value'];
                }
            }
        }
        return null;
    }

    public function effectivePath(string $rel): string
    {
        return is_file($this->inst->localPath($rel)) ? $this->inst->localPath($rel) : $this->inst->origPath($rel);
    }

    /** @return array<string, mixed> */
    public function info(string $rel): array
    {
        $curated = $this->curated();
        [$group, $label, $effect] = $curated[$rel] ?? ['group.other', '', 'immediate'];
        return [
            'rel' => $rel,
            'name' => basename($rel),
            'group' => $group,
            'label' => $label,
            'effect' => $effect,
            'type' => self::type($rel),
            'hasLocal' => is_file($this->inst->localPath($rel)),
            'hasOrig' => is_file($this->inst->origPath($rel)),
            'protected' => in_array($rel, $this->inst->protectedFiles, true),
            'curated' => isset($curated[$rel]),
        ];
    }

    /** All other top-level config files of the installation */
    public function otherFiles(): array
    {
        $known = array_keys($this->curated());
        $out = [];
        foreach (glob($this->inst->home . '/config/vufind/*.{ini,yaml}', GLOB_BRACE) ?: [] as $p) {
            $rel = 'config/vufind/' . basename($p);
            if (!in_array($rel, $known, true)) {
                $out[] = $rel;
            }
        }
        return $out;
    }

    public function mtime(string $rel): int
    {
        return @filemtime($this->inst->localPath($rel)) ?: 0;
    }

    /** Refuse to write if the local file changed after the client loaded it */
    public function assertUnchanged(string $rel, mixed $clientMtime): void
    {
        $local = $this->inst->localPath($rel);
        if (is_file($local) && $clientMtime && filemtime($local) != $clientMtime) {
            throw new ConflictException('error.changed_outside');
        }
    }

    /** Copy the current local file into the backup directory; returns its path relative to the backup dir */
    public function backup(string $rel): ?string
    {
        $src = $this->inst->localPath($rel);
        if (!is_file($src)) {
            return null;
        }
        $dir = $this->inst->backupDir . '/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new HttpException('error.backup_failed', ['path' => $dir], 500);
        }
        $name = basename($rel) . '.' . date('Ymd-His');
        // Several saves within a second must not overwrite each other
        for ($n = 2; is_file("$dir/$name"); $n++) {
            $name = basename($rel) . '.' . date('Ymd-His') . "-$n";
        }
        if (!copy($src, "$dir/$name")) {
            throw new HttpException('error.backup_failed', ['path' => "$dir/$name"], 500);
        }
        return dirname($rel) . '/' . $name;
    }

    /** Create the local copy from the original (or empty) if it does not exist yet */
    public function ensureLocal(string $rel): bool
    {
        $dst = $this->inst->localPath($rel);
        if (is_file($dst)) {
            return false;
        }
        $orig = $this->inst->origPath($rel);
        $this->write($rel, is_file($orig) ? (string)file_get_contents($orig) : '');
        return true;
    }

    public function write(string $rel, string $content): void
    {
        $dst = $this->inst->localPath($rel);
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true)) {
            throw new HttpException('error.write_failed', ['path' => $dst], 500);
        }
        // In place (not temp file + rename): keeps owner, group and mode of the
        // existing file, e.g. a config.ini readable only by the web server group
        if (@file_put_contents($dst, $content, LOCK_EX) === false) {
            throw new HttpException('error.write_failed', ['path' => $dst], 500);
        }
    }

    public function deleteLocal(string $rel): void
    {
        if (is_file($this->inst->localPath($rel)) && !@unlink($this->inst->localPath($rel))) {
            throw new HttpException('error.write_failed', ['path' => $this->inst->localPath($rel)], 500);
        }
    }

    /** @return array<string, int> number of removed files per cache directory */
    public function clearCaches(): array
    {
        $cleared = [];
        foreach (self::CACHE_DIRS as $d) {
            $path = $this->inst->local . '/cache/' . $d;
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            $n = 0;
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $n++);
            }
            $cleared[$d] = $n;
        }
        return $cleared;
    }

    /** Unified diff original → local, via the diff binary (no shell involved) */
    public function diff(string $rel): ?string
    {
        $local = $this->inst->localPath($rel);
        if (!is_file($local)) {
            return '';
        }
        $orig = is_file($this->inst->origPath($rel)) ? $this->inst->origPath($rel) : '/dev/null';
        $proc = @proc_open(
            ['diff', '-u', '--label', "original/$rel", '--label', "local/$rel", $orig, $local],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Exit code 2 = trouble (e.g. diff not installed)
        return proc_close($proc) > 1 ? null : (string)$out;
    }
}
