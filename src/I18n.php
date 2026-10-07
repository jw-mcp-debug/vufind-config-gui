<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Translations from lang/<code>.json, shared by PHP (error messages) and the
 * browser (the JSON is embedded into the page). Missing keys fall back to
 * English, then to the key itself.
 */
final class I18n
{
    public const LANGUAGES = ['en' => 'English', 'de' => 'Deutsch'];
    public const COOKIE = 'vcg_lang';

    /** @var array<string, string> */
    private array $strings;

    /** @var array<string, string> */
    private array $fallback;

    public function __construct(public readonly string $lang, ?string $dir = null)
    {
        $dir ??= dirname(__DIR__) . '/lang';
        $this->fallback = self::load($dir . '/en.json');
        $this->strings = $lang === 'en' ? $this->fallback : self::load($dir . "/$lang.json") + $this->fallback;
    }

    /** Pick the language: ?lang=, cookie, configured default, Accept-Language, English */
    public static function negotiate(string $configured, ?string $query, ?string $cookie, string $acceptLanguage): string
    {
        foreach ([$query, $cookie, $configured === 'auto' ? null : $configured] as $candidate) {
            if ($candidate !== null && isset(self::LANGUAGES[$candidate])) {
                return $candidate;
            }
        }
        foreach (explode(',', $acceptLanguage) as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (isset(self::LANGUAGES[$code])) {
                return $code;
            }
        }
        return 'en';
    }

    /** @param array<string, scalar> $params */
    public function t(string $key, array $params = []): string
    {
        $text = $this->strings[$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->strings;
    }

    /** @return array<string, string> */
    private static function load(string $path): array
    {
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($data) ? $data : [];
    }
}
