<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Request checks. The GUI writes VuFind's configuration (which contains
 * database and ILS passwords), so even on 127.0.0.1 it must defend against
 * attacks that run through the admin's own browser:
 *
 * - DNS rebinding: a foreign domain resolving to 127.0.0.1 could read the
 *   API. Countered by an allow-list for the Host header.
 * - Cross-site requests (CSRF): any web page can send a "simple" POST to
 *   localhost. Every API call must carry a custom header, which a foreign
 *   page can only add after a CORS preflight that this app never approves.
 * - Clickjacking: framing is forbidden.
 *
 * Optional HTTP Basic authentication adds a password on top.
 */
final class Security
{
    public const API_HEADER = 'HTTP_X_VUFIND_CONFIG_GUI';

    public function __construct(private readonly Settings $settings, private readonly array $server)
    {
    }

    public function sendHeaders(bool $api): void
    {
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if ($api) {
            header('Cache-Control: no-store');
        }
    }

    /** Host header must be on the allow-list ("*" disables the check) */
    public function hostAllowed(): bool
    {
        $allowed = $this->settings->allowedHosts;
        if (in_array('*', $allowed, true)) {
            return true;
        }
        $host = strtolower((string)($this->server['HTTP_HOST'] ?? ''));
        // Strip the port, keeping IPv6 brackets: "[::1]:8181" → "[::1]"
        $name = preg_match('/^(\[[^\]]+\]|[^:]+)(:\d+)?$/', $host, $m) ? $m[1] : $host;
        return in_array($name, $allowed, true);
    }

    /** True if no password is configured or the Basic credentials match */
    public function authenticated(): bool
    {
        $auth = $this->settings->auth;
        if ($auth === null) {
            return true;
        }
        $user = (string)($this->server['PHP_AUTH_USER'] ?? '');
        $pass = (string)($this->server['PHP_AUTH_PW'] ?? '');
        return hash_equals($auth['user'], $user) && password_verify($pass, $auth['password_hash']);
    }

    /**
     * API calls must come from the GUI's own page: custom header present,
     * JSON body for writes, and (where the browser sends it) same origin.
     */
    public function apiRequestAllowed(): bool
    {
        if (($this->server[self::API_HEADER] ?? '') !== '1') {
            return false;
        }
        $site = $this->server['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }
        if (($this->server['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            return str_starts_with(strtolower((string)($this->server['CONTENT_TYPE'] ?? '')), 'application/json');
        }
        return true;
    }
}
