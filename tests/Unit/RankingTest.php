<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VuFindConfigGui\Ranking;

final class RankingTest extends TestCase
{
    private const SPEC = ['DismaxFields' => ['title^500', 'allfields'], 'DismaxHandler' => 'edismax'];

    private static function params(?array $params): array
    {
        $out = [];
        foreach ($params ?? [] as [$k, $v]) {
            $out[$k][] = $v;
        }
        return $out;
    }

    public function testDefaultMmDependsOnHandler(): void
    {
        [$p] = Ranking::solrParams(self::SPEC, 'library', 'AllFields', [], []);
        $this->assertSame(['0%'], self::params($p)['mm']);
        $this->assertSame(['title^500 allfields'], self::params($p)['qf']);

        [$p] = Ranking::solrParams(['DismaxHandler' => 'dismax'] + self::SPEC, 'library', 'AllFields', [], []);
        $this->assertSame(['100%'], self::params($p)['mm']);
    }

    public function testOwnMmIsKept(): void
    {
        [$p] = Ranking::solrParams(self::SPEC + ['DismaxParams' => [['mm', '2<-1']]], 'a b c', 'AllFields', [], []);
        $this->assertSame(['2<-1'], self::params($p)['mm']);
    }

    public function testExactSettingsForQuotedSearch(): void
    {
        $spec = self::SPEC + ['ExactSettings' => ['DismaxFields' => ['title_unstemmed^900']]];
        [$p, $note] = Ranking::solrParams($spec, '"exact title"', 'Title', [], []);
        $this->assertSame(['title_unstemmed^900'], self::params($p)['qf']);
        $this->assertSame('ranking.note_exact', $note);
    }

    public function testQueryFieldsOnlyIsUnsupported(): void
    {
        [$p, $note] = Ranking::solrParams(['QueryFields' => ['id' => [['onephrase', null]]]], 'x', 'id', [], []);
        $this->assertNull($p);
        $this->assertSame('ranking.unsupported_queryfields', $note);
    }

    public function testDismaxWithAdvancedSyntaxIsUnsupported(): void
    {
        [$p] = Ranking::solrParams(['DismaxHandler' => 'dismax'] + self::SPEC, 'a OR b', 'AllFields', [], []);
        $this->assertNull($p);
    }

    public function testGlobalExtraParamsWithConditions(): void
    {
        $global = [
            ['param' => 'bf', 'value' => 'recip(rord(publishDateSort),1,1000,1000)',
                'conditions' => [['SearchTypeIn' => ['AllFields']], ['NoDismaxParams' => ['bf']], ['SortIn' => ['score desc']]]],
            ['param' => 'bq', 'value' => ['format:Book^5', 'language:German^2'], 'conditions' => [['SearchTypeNotIn' => ['AllFields']]]],
        ];
        [$p] = Ranking::solrParams(self::SPEC, 'x', 'AllFields', $global, ['AllFields' => self::SPEC]);
        $this->assertSame(['recip(rord(publishDateSort),1,1000,1000)'], self::params($p)['bf']);
        $this->assertArrayNotHasKey('bq', self::params($p));

        [$p] = Ranking::solrParams(self::SPEC, 'x', 'Title', $global, ['Title' => self::SPEC]);
        $this->assertArrayNotHasKey('bf', self::params($p));
        $this->assertSame(['format:Book^5', 'language:German^2'], self::params($p)['bq']);
    }

    public function testNoDismaxParamsBlocksWhenTypeSetsParam(): void
    {
        $spec = self::SPEC + ['DismaxParams' => [['bf', 'x']]];
        $this->assertFalse(Ranking::conditionsMatch([['NoDismaxParams' => ['bf']]], 'AllFields', 'score desc', ['AllFields' => $spec]));
    }

    public function testForeignCommentsDetection(): void
    {
        $this->assertFalse(Ranking::hasForeignComments(Ranking::header() . "AllFields:\n  DismaxFields: [a]\n"));
        $this->assertTrue(Ranking::hasForeignComments(Ranking::header() . "# my note\nAllFields: {}\n"));
    }
}
