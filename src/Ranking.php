<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Rebuilds the Solr request VuFind's QueryBuilder sends for a simple search
 * with one search type (searchspecs.yaml), so the ranking editor can compare
 * saved and draft settings directly against Solr.
 *
 * Covers DismaxFields/DismaxHandler/DismaxParams/FilterQuery, the default
 * "mm", ExactSettings for quoted searches and GlobalExtraParams with their
 * conditions. QueryFields (Lucene munge rules) are not reproduced.
 */
final class Ranking
{
    public const FILE = 'config/vufind/searchspecs.yaml';

    /** Directives the editor cannot represent; such files are left to the raw editor */
    public const DIRECTIVES = ['@parent_yaml', '@parent_config_name', '@merge_sections'];

    public const RESULT_FIELDS = 'id,title,author,author2,publishDate,format,score';

    /**
     * @param array<string, mixed>              $spec     the search type's settings
     * @param array<int, array<string, mixed>>  $global   GlobalExtraParams
     * @param array<string, array<string, mixed>> $allSpecs settings of all search types (for NoDismaxParams)
     *
     * @return array{0: array<int, array{0: string, 1: string}>|null, 1: string} [params or null, note key]
     */
    public static function solrParams(array $spec, string $q, string $type, array $global, array $allSpecs, int $rows = 20): array
    {
        $q = trim($q);
        $note = '';
        if (preg_match('/^".*"$/', $q) && !empty($spec['ExactSettings']) && is_array($spec['ExactSettings'])) {
            $spec = $spec['ExactSettings'];
            $note = 'ranking.note_exact';
        }
        if (empty($spec['DismaxFields'])) {
            return [null, 'ranking.unsupported_queryfields'];
        }
        $handler = $spec['DismaxHandler'] ?? 'edismax';
        $dismaxParams = [];
        foreach ((array)($spec['DismaxParams'] ?? []) as $p) {
            if (is_array($p) && count($p) >= 2) {
                $dismaxParams[] = [(string)$p[0], (string)$p[1]];
            }
        }
        // As SearchHandler::setDefaultMustMatch(): edismax 0 %, dismax 100 %
        if (!in_array('mm', array_column($dismaxParams, 0), true)) {
            $dismaxParams[] = ['mm', $handler === 'edismax' ? '0%' : '100%'];
        }
        if ($handler !== 'edismax' && preg_match('/\b(AND|OR|NOT)\b|[*?~^():\[\]{}]|(^|\s)[+-]\S/', $q)) {
            // VuFind switches to QueryFields for advanced syntax with plain dismax
            return [null, 'ranking.unsupported_dismax_syntax'];
        }
        $params = [['q', $q === '' ? '*:*' : $q], ['defType', $handler], ['qf', implode(' ', (array)$spec['DismaxFields'])]];
        array_push($params, ...$dismaxParams);
        if (!empty($spec['FilterQuery'])) {
            $params[] = ['fq', (string)$spec['FilterQuery']];
        }
        $sort = 'score desc';
        foreach ($global as $gp) {
            if (!is_array($gp) || empty($gp['param']) || empty($gp['value'])) {
                continue;
            }
            if (!self::conditionsMatch((array)($gp['conditions'] ?? []), $type, $sort, $allSpecs)) {
                continue;
            }
            foreach ((array)$gp['value'] as $v) {
                $params[] = [(string)$gp['param'], (string)$v];
            }
        }
        array_push($params, ['sort', $sort], ['rows', (string)$rows], ['fl', self::RESULT_FIELDS]);
        return [$params, $note];
    }

    /** Conditions of GlobalExtraParams as in QueryBuilder::checkParamConditions() */
    public static function conditionsMatch(array $conditions, string $type, string $sort, array $allSpecs): bool
    {
        foreach ($conditions as $c) {
            if (!is_array($c) || !$c) {
                continue;
            }
            $values = (array)reset($c);
            $ok = match (key($c)) {
                'SearchTypeIn', 'AllSearchTypesIn' => in_array($type, $values, true),
                'SearchTypeNotIn' => !in_array($type, $values, true),
                'NoDismaxParams' => !array_intersect($values, array_map(
                    fn ($p) => is_array($p) ? ($p[0] ?? null) : null,
                    (array)($allSpecs[$type]['DismaxParams'] ?? [])
                )),
                'SortIn' => in_array($sort, $values, true),
                'SortNotIn' => !in_array($sort, $values, true),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Header for the written file; must stay in sync with hasForeignComments() */
    public static function header(): string
    {
        return "---\n# Written by vufind-config-gui: only search types that differ from the original.\n"
            . "# All other search types are inherited from config/vufind/searchspecs.yaml.\n"
            . "# Docs: https://vufind.org/wiki/configuration:search_specifications\n\n";
    }

    /** True if the local file has comments other than the GUI's header (they are lost on save) */
    public static function hasForeignComments(string $raw): bool
    {
        $own = array_filter(array_map('trim', explode("\n", self::header())), fn ($l) => str_starts_with($l, '#'));
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') && !in_array($line, $own, true)) {
                return true;
            }
        }
        return false;
    }
}
