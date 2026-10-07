<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Search across all configuration files of an instance: keys, values,
 * section names and help texts of the effective file (local copy, else
 * original). All search words must match.
 */
final class SettingsSearch
{
    private const MAX_HITS = 150;
    private const MAX_YAML_HITS_PER_FILE = 10;

    public function __construct(private readonly FileStore $files)
    {
    }

    /** @return array{hits: array<int, array<string, mixed>>, total: int, files: int} */
    public function search(string $q): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/', self::lower(trim($q)))));
        if (!$terms) {
            return ['hits' => [], 'total' => 0, 'files' => 0];
        }
        $has = static function (string $text) use ($terms): bool {
            $text = self::lower($text);
            foreach ($terms as $t) {
                if (!str_contains($text, $t)) {
                    return false;
                }
            }
            return true;
        };
        $hits = [];
        $fileCount = 0;
        foreach (array_merge(array_keys($this->files->curated()), $this->files->otherFiles()) as $rel) {
            $info = $this->files->info($rel);
            $path = $this->files->effectivePath($rel);
            if (!is_file($path)) {
                continue;
            }
            $before = count($hits);
            if ($info['type'] === 'yaml') {
                $found = 0;
                foreach (LineConfig::readLines($path) as $i => $line) {
                    if (trim($line) !== '' && $has($line) && $found++ < self::MAX_YAML_HITS_PER_FILE) {
                        $hits[] = $this->hit($info, '', trim($line), '', !str_starts_with(ltrim($line), '#'), $i, 1, 'yaml');
                    }
                }
            } else {
                foreach (LineConfig::parse(LineConfig::readLines($path), $info['type']) as $s) {
                    foreach ($s['entries'] as $e) {
                        $inKey = $has($e['key']);
                        $inValue = $has($e['value']);
                        if (!$inKey && !$inValue && !$has("{$s['name']} {$e['key']} {$e['value']} {$e['help']}")) {
                            continue;
                        }
                        // Exact key first, then key, value, help text; active options before commented ones
                        $score = ($inKey ? (self::lower($e['key']) === $terms[0] ? 4 : 3) : ($inValue ? 2 : 1))
                            + ($e['active'] ? 0.5 : 0);
                        $hits[] = $this->hit($info, $s['name'], $e['key'], $e['value'], $e['active'], $e['line'], $score, 'entry')
                            + ['help' => self::cut(trim(strtok($e['help'], "\n") ?: ''), 160)];
                    }
                }
            }
            $fileCount += count($hits) > $before ? 1 : 0;
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);
        return ['hits' => array_slice($hits, 0, self::MAX_HITS), 'total' => count($hits), 'files' => $fileCount];
    }

    private function hit(array $info, string $section, string $key, string $value, bool $active, int $line, float $score, string $kind): array
    {
        return ['rel' => $info['rel'], 'file' => $info['name'], 'group' => $info['group'], 'section' => $section,
            'key' => $key, 'value' => $value, 'active' => $active, 'line' => $line, 'score' => $score, 'kind' => $kind];
    }

    private static function cut(string $s, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }
}
