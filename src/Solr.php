<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/** Read-only helper queries against the instance's Solr core. */
final class Solr
{
    public function __construct(private readonly ?string $baseUrl)
    {
    }

    public function available(): bool
    {
        return $this->baseUrl !== null;
    }

    /** Values of the "format" field with counts, as suggestions for content type filters */
    public function formats(): array
    {
        $d = $this->get('select', ['q' => '*:*', 'rows' => 0, 'facet' => 'true', 'facet.field' => 'format', 'facet.limit' => 100]);
        $list = $d['facet_counts']['facet_fields']['format'] ?? [];
        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $out[] = ['value' => $list[$i], 'count' => $list[$i + 1]];
        }
        return $out;
    }

    /** Number of documents matching a filter query, or the Solr error */
    public function filterCount(string $fq): array
    {
        $d = $this->get('select', ['q' => '*:*', 'rows' => 0, 'fq' => $fq]);
        return isset($d['response']) ? ['count' => $d['response']['numFound']] : ['error' => self::error($d)];
    }

    /** Indexed fields with number of documents and type, for the field picker */
    public function fieldStats(): array
    {
        $d = $this->get('admin/luke', ['numTerms' => 0]);
        $out = [];
        foreach ($d['fields'] ?? [] as $name => $f) {
            // Only indexed fields (schema flag "I" first) are searchable
            if (($f['schema'][0] ?? '-') !== 'I') {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $f['type'] ?? '', 'docs' => $f['docs'] ?? 0];
        }
        return ['total' => $d['index']['numDocs'] ?? 0, 'fields' => $out];
    }

    /**
     * Run a search with the given parameter list (repeatable names allowed).
     *
     * @param array<int, array{0: string, 1: string}> $params
     */
    public function query(array $params): array
    {
        $d = $this->get('select', $params);
        if (!isset($d['response'])) {
            return ['error' => self::error($d)];
        }
        $first = fn ($v) => is_array($v) ? ($v[0] ?? '') : ($v ?? '');
        $docs = array_map(fn ($x) => [
            'id' => $x['id'],
            'title' => $first($x['title'] ?? ''),
            'author' => implode('; ', array_slice(array_merge((array)($x['author'] ?? []), (array)($x['author2'] ?? [])), 0, 2)),
            'year' => implode(', ', (array)($x['publishDate'] ?? [])),
            'format' => implode(', ', (array)($x['format'] ?? [])),
            'score' => $x['score'] ?? null,
        ], $d['response']['docs']);
        return ['numFound' => $d['response']['numFound'], 'docs' => $docs];
    }

    /** @param array<string|int, mixed> $params associative or list of [name, value] */
    private function get(string $path, array $params): ?array
    {
        if ($this->baseUrl === null) {
            throw new HttpException('error.no_solr');
        }
        $pairs = array_is_list($params) ? $params : array_map(null, array_keys($params), array_values($params));
        $pairs[] = ['wt', 'json'];
        $qs = implode('&', array_map(fn ($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs));
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
        $json = @file_get_contents($this->baseUrl . '/' . $path . '?' . $qs, false, $ctx);
        $d = json_decode((string)$json, true);
        return is_array($d) ? $d : null;
    }

    private static function error(?array $d): string
    {
        if (isset($d['error'])) {
            // With status 500 Solr often has no msg, only a trace
            return $d['error']['msg'] ?? strtok($d['error']['trace'] ?? 'unknown error', "\n");
        }
        return 'Solr not reachable';
    }
}
