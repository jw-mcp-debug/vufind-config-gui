<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Line-based reader/writer for VuFind's .ini and .properties files.
 *
 * Unlike parse_ini_file() it keeps every line, so comments, commented-out
 * options and formatting survive an edit. Values are decoded the way PHP's
 * INI_SCANNER_NORMAL reads them (which is what VuFind uses), so what the GUI
 * shows is what VuFind sees.
 */
final class LineConfig
{
    private const KEY_RE = '([A-Za-z0-9_.\-]+(?:\[[^\]=]*\])?)';

    /** Maximum number of comment lines kept as help text above an option */
    private const HELP_LINES = 25;

    public static function commentChar(string $type): string
    {
        return $type === 'properties' ? '#' : ';';
    }

    /**
     * Parse lines into sections with entries.
     *
     * Each entry: line (0-based), key, value (decoded), active, help (comment
     * block directly above). Commented-out options ("; key = value") are
     * recognised as inactive entries.
     *
     * @param string[] $lines
     *
     * @return array<int, array{name: string, help: string, entries: array<int, array<string, mixed>>}>
     */
    public static function parse(array $lines, string $type): array
    {
        $c = self::commentChar($type);
        $q = preg_quote($c, '/');
        $sections = [];
        $current = ['name' => '', 'entries' => [], 'help' => []];
        $help = [];
        foreach ($lines as $i => $line) {
            if ($type === 'ini' && preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                if ($current['entries'] || $current['name'] !== '') {
                    $sections[] = $current;
                }
                $current = ['name' => $m[1], 'entries' => [], 'help' => $help];
                $help = [];
                continue;
            }
            if (
                preg_match('/^(\s*)(' . $q . '+\s*)?' . self::KEY_RE . '\s*=\s?(.*)$/', $line, $m)
                // A commented line is only an option if PHP could read it once uncommented
                && ($m[2] === '' || $type !== 'ini' || self::isValidIniOption($m[3], $m[4]))
            ) {
                [$value] = self::splitValue($m[4], $type);
                $current['entries'][] = [
                    'line' => $i,
                    'key' => $m[3],
                    'value' => $value,
                    'active' => $m[2] === '',
                    'help' => implode("\n", $help),
                ];
                $help = [];
                continue;
            }
            if (preg_match('/^\s*' . $q . '+ ?(.*)$/', $line, $m)) {
                $help[] = $m[1];
                if (count($help) > self::HELP_LINES) {
                    array_shift($help);
                }
            } elseif (trim($line) === '' && $help && end($help) !== '') {
                // A blank line separates paragraphs but keeps the help block
                $help[] = '';
            }
        }
        $sections[] = $current;
        foreach ($sections as &$s) {
            $s['help'] = trim(implode("\n", $s['help']));
        }
        return $sections;
    }

    /**
     * Split the raw text after "=" into decoded value, quote style and trailing
     * part (whitespace plus inline comment), so that
     * leading whitespace + raw token + trail reproduces the input.
     *
     * @return array{0: string, 1: string, 2: string} [value, quote ('"', "'", '' or 'mixed'), trail]
     */
    public static function splitValue(string $raw, string $type): array
    {
        $raw = ltrim($raw);
        if ($type !== 'ini') {
            // Java properties have no inline comments
            $value = rtrim($raw);
            return [$value, '', substr($raw, strlen($value))];
        }
        // PHP reads a value as a sequence of segments and concatenates them:
        // "double quoted" (only \" and \\ unescaped), 'single quoted' (raw) and
        // unquoted runs (trimmed). An unquoted ";" starts a comment.
        // Examples: "a", "b" → a,b   "x=""y""" → x=y   host:"name" → host:name
        $value = '';
        $quotes = [];
        $end = 0; // end of the last segment; the trail starts here
        $len = strlen($raw);
        $pos = 0;
        while ($pos < $len && $raw[$pos] !== ';') {
            $ch = $raw[$pos];
            if ($ch === '"' && preg_match('/"((?:[^"\\\\]|\\\\.)*)"/sA', $raw, $m, 0, $pos)) {
                $value .= strtr($m[1], ['\\"' => '"', '\\\\' => '\\']);
                $quotes[] = '"';
                $pos = $end = $pos + strlen($m[0]);
            } elseif ($ch === "'" && preg_match("/'([^']*)'/A", $raw, $m, 0, $pos)) {
                $value .= $m[1];
                $quotes[] = "'";
                $pos = $end = $pos + strlen($m[0]);
            } else {
                // Unquoted run up to the next quote or comment (an unmatched quote is taken literally)
                $next = strcspn($raw, "\"';", $pos + 1) + $pos + 1;
                $run = substr($raw, $pos, $next - $pos);
                if (trim($run) !== '') {
                    $value .= trim($run);
                    $quotes[] = '';
                    $end = $pos + strlen(rtrim($run));
                }
                $pos = $next;
            }
        }
        $quote = match (true) {
            count($quotes) === 1 => $quotes[0],
            count($quotes) > 1 => 'mixed',
            default => '',
        };
        return [$value, $quote, substr($raw, $end)];
    }

    /**
     * True if PHP could read "key = rest" as an option. Used to tell
     * commented-out options from prose in help texts, e.g.
     * "; always => behaviour enabled" or "; west = -179 and east = -180."
     */
    public static function isValidIniOption(string $key, string $rest): bool
    {
        set_error_handler(static fn () => true);
        try {
            return parse_ini_string("$key = $rest", false, INI_SCANNER_NORMAL) !== false;
        } finally {
            restore_error_handler();
        }
    }

    /** Render a value for writing, quoting where PHP would misread it */
    public static function formatValue(string $value, string $type, string $quote = ''): string
    {
        if ($type !== 'ini') {
            return $value;
        }
        if ($quote === "'" && !str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        $needsQuotes = $value === '' || preg_match('/[;=&|!~^(){}"\'\[\]$\s]/', $value)
            || preg_match('/^(null|yes|no|on|off|none)$/i', $value);
        if ($quote === '"' || $quote === 'mixed' || ($needsQuotes && !preg_match('/^(true|false|-?\d+(\.\d+)?)$/', $value))) {
            return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
        }
        return $value;
    }

    /**
     * Map section + key + occurrence to value/active/help. Repeated keys
     * (e.g. "initialResults[] = ...") are told apart by their occurrence number.
     *
     * @return array<string, array{value: string, active: bool, help: string}>
     */
    public static function valueMap(array $sections): array
    {
        $map = [];
        foreach ($sections as $s) {
            $count = [];
            foreach ($s['entries'] as $e) {
                $n = $count[$e['key']] = ($count[$e['key']] ?? 0) + 1;
                $map[self::mapKey($s['name'], $e['key'], $n)] = [
                    'value' => $e['value'], 'active' => $e['active'], 'help' => $e['help'],
                ];
            }
        }
        return $map;
    }

    public static function mapKey(string $section, string $key, int $occurrence): string
    {
        return $section . "\0" . $key . "\0" . $occurrence;
    }

    /**
     * Apply structured changes to the lines of a file.
     *
     * A change only touches what it alters: an unchanged value keeps its exact
     * spelling and quotes, an unchanged state keeps its comment prefix.
     *
     * @param string[]                                                         $lines
     * @param array<int, array{line: int, key: string, value: string, active: bool}> $changes
     *
     * @return string[]
     *
     * @throws ConflictException if a line no longer holds the expected key
     */
    public static function applyChanges(array $lines, array $changes, string $type): array
    {
        $c = self::commentChar($type);
        $q = preg_quote($c, '/');
        foreach ($changes as $ch) {
            $i = (int)$ch['line'];
            $key = (string)$ch['key'];
            if (!isset($lines[$i])) {
                throw new ConflictException('error.line_missing', ['line' => $i]);
            }
            $re = '/^(\s*)(' . $q . '+\s*)?' . preg_quote($key, '/') . '(\s*=\s?)(.*)$/';
            if (!preg_match($re, $lines[$i], $m)) {
                throw new ConflictException('error.line_changed', ['line' => $i, 'key' => $key]);
            }
            $wasActive = $m[2] === '';
            $active = (bool)$ch['active'];
            $prefix = $active === $wasActive ? $m[2] : ($active ? '' : $c);

            $rest = $m[4];
            $lead = substr($rest, 0, strlen($rest) - strlen(ltrim($rest)));
            [$old, $quote, $trail] = self::splitValue($rest, $type);
            $value = (string)$ch['value'];
            if ($value === $old) {
                $valuePart = $rest;
            } else {
                $valuePart = $lead . self::formatValue($value, $type, $quote) . $trail;
            }
            $lines[$i] = $m[1] . $prefix . $key . $m[3] . $valuePart;
        }
        return $lines;
    }

    /** @return string[] */
    public static function readLines(string $path): array
    {
        return is_file($path) ? preg_split('/\r?\n/', (string)file_get_contents($path)) : [];
    }
}
