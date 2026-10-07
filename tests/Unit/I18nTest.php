<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VuFindConfigGui\I18n;

final class I18nTest extends TestCase
{
    private static function load(string $lang): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 2) . "/lang/$lang.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAllLanguagesHaveTheSameKeysAndPlaceholders(): void
    {
        $en = self::load('en');
        foreach (array_keys(I18n::LANGUAGES) as $lang) {
            $other = self::load($lang);
            $this->assertSame([], array_diff(array_keys($en), array_keys($other)), "$lang lacks keys");
            $this->assertSame([], array_diff(array_keys($other), array_keys($en)), "$lang has extra keys");
            foreach ($en as $key => $text) {
                preg_match_all('/\{\w+\}/', $text, $a);
                preg_match_all('/\{\w+\}/', $other[$key], $b);
                sort($a[0]);
                sort($b[0]);
                $this->assertSame($a[0], $b[0], "placeholders differ in $lang:$key");
            }
        }
    }

    public function testEveryKeyUsedInCodeExists(): void
    {
        $root = dirname(__DIR__, 2);
        $code = implode("\n", array_map('file_get_contents', array_merge(
            glob("$root/public/assets/*.js"),
            [$root . '/public/index.php'],
            glob("$root/src/*.php"),
            glob("$root/src/*/*.php"),
        )));
        preg_match_all("/\\bt\\('([a-z_]+\\.[A-Za-z0-9_.]+)'/", $code, $m1);
        // Keys passed around as plain strings in PHP (exceptions, file groups)
        $php = implode("\n", array_map('file_get_contents', array_merge(glob("$root/src/*.php"), glob("$root/src/*/*.php"), [$root . '/public/index.php'])));
        preg_match_all("/'((?:error|mcp|ranking|file|group)\\.[a-z0-9_]+)'/", $php, $m2);
        $en = self::load('en');
        // Prefixes of dynamic keys (t('effect.' + x)) end with "." or "_"
        $used = array_filter(array_unique(array_merge($m1[1], $m2[1])), fn ($k) => !preg_match('/[._]$|\.(js|css)$/', $k));
        $missing = array_diff($used, array_keys($en));
        $this->assertSame([], array_values($missing));
    }

    public function testNegotiation(): void
    {
        $this->assertSame('de', I18n::negotiate('auto', null, null, 'de-DE,de;q=0.9,en;q=0.8'));
        $this->assertSame('en', I18n::negotiate('auto', null, null, 'fr-FR'));
        $this->assertSame('de', I18n::negotiate('de', null, null, 'en'));
        $this->assertSame('en', I18n::negotiate('de', null, 'en', 'de'));
        $this->assertSame('de', I18n::negotiate('en', 'de', 'en', 'en'));
        $this->assertSame('en', I18n::negotiate('auto', 'xx', null, ''));
    }

    public function testPlaceholders(): void
    {
        $i18n = new I18n('en');
        $this->assertSame('3 changed', $i18n->t('settings.n_changed', ['n' => 3]));
        $this->assertSame('no.such.key', $i18n->t('no.such.key'));
    }
}
