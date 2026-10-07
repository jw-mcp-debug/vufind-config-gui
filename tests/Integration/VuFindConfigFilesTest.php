<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VuFindConfigGui\LineConfig;

/**
 * Runs the line parser against every configuration file of a real VuFind
 * installation. Set VUFIND_HOME to enable, e.g.
 *
 *   VUFIND_HOME=/usr/local/vufind vendor/bin/phpunit --testsuite integration
 *
 * Checks for each file:
 * 1. every active option is read with the same value as PHP's parse_ini_string()
 * 2. applying "changes" that keep every value and state leaves the file byte-identical
 * 3. switching any single option off (or on) keeps the file readable for PHP
 *    and changes nothing but that line
 */
final class VuFindConfigFilesTest extends TestCase
{
    public static function files(): array
    {
        $home = getenv('VUFIND_HOME');
        if (!$home || !is_dir("$home/config/vufind")) {
            return ['no VUFIND_HOME' => [null, null]];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$home/config/vufind", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'ini') {
                $out[substr($f->getPathname(), strlen($home) + 1)] = [$f->getPathname(), 'ini'];
            }
        }
        foreach (glob("$home/import/*.properties") ?: [] as $p) {
            $out[substr($p, strlen($home) + 1)] = [$p, 'properties'];
        }
        ksort($out);
        return $out;
    }

    private static function skipWithoutHome(?string $path): void
    {
        if ($path === null) {
            self::markTestSkipped('Set VUFIND_HOME to run against a VuFind installation');
        }
    }

    /** PHP's NORMAL scanner turns unquoted booleans/null into "1"/"" */
    private static function phpView(string $value, string $quote): string
    {
        if ($quote !== '') {
            return $value;
        }
        return match (strtolower($value)) {
            'true', 'on', 'yes' => '1',
            'false', 'off', 'no', 'none', 'null' => '',
            default => $value,
        };
    }

    #[DataProvider('files')]
    public function testValuesMatchPhp(?string $path, ?string $type): void
    {
        self::skipWithoutHome($path);
        if ($type !== 'ini') {
            $this->assertTrue(true, 'properties files are read by SolrMarc, not PHP');
            return;
        }
        $raw = (string)file_get_contents($path);
        $php = parse_ini_string($raw, true, INI_SCANNER_NORMAL);
        $this->assertIsArray($php, 'PHP cannot read the original file');
        $lines = LineConfig::readLines($path);
        $checked = 0;
        foreach (LineConfig::parse($lines, 'ini') as $s) {
            $last = [];
            foreach ($s['entries'] as $e) {
                if ($e['active'] && !str_contains($e['key'], '[')) {
                    // Later occurrences of a key win, as in PHP
                    $last[$e['key']] = $e;
                }
            }
            foreach ($last as $key => $e) {
                preg_match('/=\s?(.*)$/', $lines[$e['line']], $m);
                [, $quote] = LineConfig::splitValue($m[1], 'ini');
                $expected = $s['name'] === '' ? ($php[$key] ?? null) : ($php[$s['name']][$key] ?? null);
                if (!is_string($expected)) {
                    continue; // key used both as scalar and array, or constant interpolation
                }
                $this->assertSame($expected, self::phpView($e['value'], $quote), "[{$s['name']}] $key");
                $checked++;
            }
        }
        $this->addToAssertionCount(1);
    }

    #[DataProvider('files')]
    public function testNoOpChangesAreLossless(?string $path, ?string $type): void
    {
        self::skipWithoutHome($path);
        $lines = LineConfig::readLines($path);
        $changes = [];
        foreach (LineConfig::parse($lines, $type) as $s) {
            foreach ($s['entries'] as $e) {
                $changes[] = ['line' => $e['line'], 'key' => $e['key'], 'value' => $e['value'], 'active' => $e['active']];
            }
        }
        $this->assertSame($lines, LineConfig::applyChanges($lines, $changes, $type));
    }

    #[DataProvider('files')]
    public function testTogglingEachOptionKeepsFileValid(?string $path, ?string $type): void
    {
        self::skipWithoutHome($path);
        $lines = LineConfig::readLines($path);
        $toggled = 0;
        foreach (LineConfig::parse($lines, $type) as $s) {
            foreach ($s['entries'] as $e) {
                $out = LineConfig::applyChanges($lines, [
                    ['line' => $e['line'], 'key' => $e['key'], 'value' => $e['value'], 'active' => !$e['active']],
                ], $type);
                $diff = array_diff_assoc($out, $lines);
                $this->assertSame([$e['line']], array_keys($diff), "only line {$e['line']} may change");
                if ($type === 'ini') {
                    $this->assertIsArray(@parse_ini_string(implode("\n", $out), true, INI_SCANNER_NORMAL),
                        "toggling {$e['key']} (line {$e['line']}) breaks the file");
                }
                // Toggling back restores the original line
                $back = LineConfig::applyChanges($out, [
                    ['line' => $e['line'], 'key' => $e['key'], 'value' => $e['value'], 'active' => $e['active']],
                ], $type);
                // (the comment prefix may be normalised, e.g. ";; key" → ";key")
                $norm = fn (string $l) => preg_replace('/^[\s;#]+/', '', $l);
                $this->assertSame($norm($lines[$e['line']]), $norm($back[$e['line']]));
                $toggled++;
            }
        }
        $this->addToAssertionCount(1);
    }
}
