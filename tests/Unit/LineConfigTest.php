<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VuFindConfigGui\ConflictException;
use VuFindConfigGui\LineConfig;

final class LineConfigTest extends TestCase
{
    private const INI = <<<'INI'
        ; File header

        ; Help for the section
        [Site]
        ; The site title.
        ; Second help line.
        title = "Library Catalog"
        ;theme = bootstrap5
        url = http://localhost/vufind ; trailing comment

        [Index]
        initialResults[] = Solr
        initialResults[] = SolrAuth
        INI;

    private static function lines(string $s): array
    {
        return explode("\n", $s);
    }

    public function testParseSectionsEntriesAndHelp(): void
    {
        $sections = LineConfig::parse(self::lines(self::INI), 'ini');
        $this->assertSame(['Site', 'Index'], array_column($sections, 'name'));
        $this->assertSame('File header', explode("\n", $sections[0]['help'])[0]);

        [$title, $theme, $url] = $sections[0]['entries'];
        $this->assertSame(['title', 'Library Catalog', true], [$title['key'], $title['value'], $title['active']]);
        $this->assertSame("The site title.\nSecond help line.", $title['help']);
        $this->assertSame(['theme', 'bootstrap5', false], [$theme['key'], $theme['value'], $theme['active']]);
        $this->assertSame('http://localhost/vufind', $url['value']);
        $this->assertSame(['initialResults[]', 'initialResults[]'], array_column($sections[1]['entries'], 'key'));
    }

    public function testValueMapCountsRepeatedKeys(): void
    {
        $map = LineConfig::valueMap(LineConfig::parse(self::lines(self::INI), 'ini'));
        $this->assertSame('Solr', $map[LineConfig::mapKey('Index', 'initialResults[]', 1)]['value']);
        $this->assertSame('SolrAuth', $map[LineConfig::mapKey('Index', 'initialResults[]', 2)]['value']);
    }

    /** Expected values are what PHP's INI_SCANNER_NORMAL returns (checked in testSplitMatchesPhp). */
    public static function iniValues(): array
    {
        return [
            'plain' => ['abc', 'abc', ''],
            'spaces unquoted' => ['host 9002 DEFAULT', 'host 9002 DEFAULT', ''],
            'comment with space' => ['plain ; comment', 'plain', ''],
            'comment without space' => ['plain;comment', 'plain', ''],
            'double quotes' => ['"a b"', 'a b', '"'],
            'escaped quote and backslash' => ['"say \"hi\" \\\\ end"', 'say "hi" \\ end', '"'],
            'backslash sequences stay literal' => ['"C:\\foo\\nbar"', 'C:\\foo\\nbar', '"'],
            'semicolon inside quotes' => ['"a;b" ; c', 'a;b', '"'],
            'single quotes' => ["'raw \\n'", 'raw \\n', "'"],
            'single quotes then comment' => ["'http://x';", 'http://x', "'"],
            'empty' => ['', '', ''],
            'concatenated segments' => ['"available:1", "reserve:N"', 'available:1,reserve:N', 'mixed'],
            'doubled quotes' => ['"<a href=""x"">y</a>"', '<a href=x>y</a>', 'mixed'],
            'unquoted then quoted' => ['host:"Name" ; c', 'host:Name', 'mixed'],
        ];
    }

    #[DataProvider('iniValues')]
    public function testSplitValue(string $raw, string $value, string $quote): void
    {
        [$v, $q, $trail] = LineConfig::splitValue($raw, 'ini');
        $this->assertSame($value, $v);
        $this->assertSame($quote, $q);
        // The trail (whitespace, comment) is the end of the input
        $this->assertSame($trail, substr($raw, strlen($raw) - strlen($trail)));
    }

    #[DataProvider('iniValues')]
    public function testSplitMatchesPhp(string $raw, string $value): void
    {
        $php = parse_ini_string("k = $raw", false, INI_SCANNER_NORMAL);
        $this->assertSame($php['k'], $value);
    }

    public function testProseInCommentsIsNotAnOption(): void
    {
        $lines = [
            '[Site]',
            '; always => behaviour enabled, the feature is always on',
            '; west = -179 and east = -180.',
            '; label = The header on the column',
            ';theme = bootstrap5',
            'title = x',
        ];
        $s = LineConfig::parse($lines, 'ini')[0];
        $this->assertSame(['theme', 'title'], array_column($s['entries'], 'key'));
        // The prose stays available as help text
        $this->assertStringContainsString('label = The header on the column', $s['entries'][0]['help']);
    }

    public function testChangingConcatenatedValueWritesOneString(): void
    {
        $out = LineConfig::applyChanges(['i = "available:1", "reserve:N" ; note'], [
            ['line' => 0, 'key' => 'i', 'value' => 'available:0,reserve:N', 'active' => true],
        ], 'ini');
        $this->assertSame(['i = "available:0,reserve:N" ; note'], $out);
    }

    public function testPropertiesHaveNoInlineComments(): void
    {
        $this->assertSame(['a ; b # c', '', ''], LineConfig::splitValue('a ; b # c', 'properties'));
    }

    public static function formatCases(): array
    {
        return [
            ['simple', 'simple'],
            ['true', 'true'],
            ['42', '42'],
            ['a b', '"a b"'],
            ['a;b', '"a;b"'],
            ['', '""'],
            ['say "hi"', '"say \"hi\""'],
            ['C:\\path', 'C:\\path'],
            ['C:\\my path', '"C:\\\\my path"'],
            ['none', '"none"'],
        ];
    }

    #[DataProvider('formatCases')]
    public function testFormatValueRoundTripsThroughPhp(string $value, string $expected): void
    {
        $formatted = LineConfig::formatValue($value, 'ini');
        $this->assertSame($expected, $formatted);
        if (!in_array($value, ['true'], true)) {
            $this->assertSame($value, parse_ini_string("k = $formatted", false, INI_SCANNER_NORMAL)['k']);
        }
    }

    public function testUnchangedValueKeepsExactSpelling(): void
    {
        $lines = ['patron_host = virtuaweb 9002 DEFAULT   ; note', ";  theme = 'bootstrap5'"];
        $out = LineConfig::applyChanges($lines, [
            ['line' => 0, 'key' => 'patron_host', 'value' => 'virtuaweb 9002 DEFAULT', 'active' => true],
            ['line' => 1, 'key' => 'theme', 'value' => 'bootstrap5', 'active' => false],
        ], 'ini');
        $this->assertSame($lines, $out);
    }

    public function testToggleKeepsValueAndComment(): void
    {
        $out = LineConfig::applyChanges(['  ;  url = http://x ; note'], [
            ['line' => 0, 'key' => 'url', 'value' => 'http://x', 'active' => true],
        ], 'ini');
        $this->assertSame(['  url = http://x ; note'], $out);

        $out = LineConfig::applyChanges($out, [['line' => 0, 'key' => 'url', 'value' => 'http://x', 'active' => false]], 'ini');
        $this->assertSame(['  ;url = http://x ; note'], $out);
    }

    public function testChangedValueKeepsQuoteStyleAndTrail(): void
    {
        $out = LineConfig::applyChanges(['title = "Old"  ; note', "path = 'a'", 'n = 5'], [
            ['line' => 0, 'key' => 'title', 'value' => 'New "One"', 'active' => true],
            ['line' => 1, 'key' => 'path', 'value' => 'b c', 'active' => true],
            ['line' => 2, 'key' => 'n', 'value' => 'x y', 'active' => true],
        ], 'ini');
        $this->assertSame(['title = "New \"One\""  ; note', "path = 'b c'", 'n = "x y"'], $out);
        $parsed = parse_ini_string(implode("\n", $out), false, INI_SCANNER_NORMAL);
        $this->assertSame(['title' => 'New "One"', 'path' => 'b c', 'n' => 'x y'], $parsed);
    }

    public function testPropertiesUseHashComments(): void
    {
        $out = LineConfig::applyChanges(['#title = 245ab', 'author = 100a'], [
            ['line' => 0, 'key' => 'title', 'value' => '245abc', 'active' => true],
            ['line' => 1, 'key' => 'author', 'value' => '100a', 'active' => false],
        ], 'properties');
        $this->assertSame(['title = 245abc', '#author = 100a'], $out);
    }

    public function testConflictWhenLineChanged(): void
    {
        $this->expectException(ConflictException::class);
        LineConfig::applyChanges(['other = 1'], [['line' => 0, 'key' => 'title', 'value' => 'x', 'active' => true]], 'ini');
    }

    public function testConflictWhenLineMissing(): void
    {
        $this->expectException(ConflictException::class);
        LineConfig::applyChanges([], [['line' => 3, 'key' => 'title', 'value' => 'x', 'active' => true]], 'ini');
    }

    public function testKeyIsNotMatchedAsPrefix(): void
    {
        // A change for "url" must not touch "url_extra"
        $this->expectException(ConflictException::class);
        LineConfig::applyChanges(['url_extra = 1'], [['line' => 0, 'key' => 'url', 'value' => 'x', 'active' => true]], 'ini');
    }
}
