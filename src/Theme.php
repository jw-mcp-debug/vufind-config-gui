<?php

/**
 * vufind-config-gui – colour scheme choice (light, dark or the system's).
 *
 * Copyright (C) 2026 The vufind-config-gui contributors
 * License: GNU GPL v2 (see LICENSE)
 */

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * The choice lives in a cookie so the server can put it on <html> before the
 * first paint; the CSP forbids the inline script that would otherwise be needed.
 */
final class Theme
{
    public const COOKIE = 'vcg_theme';
    public const AUTO = 'auto';
    public const CHOICES = [self::AUTO, 'light', 'dark'];

    public static function fromCookie(?string $cookie): string
    {
        return in_array($cookie, self::CHOICES, true) ? $cookie : self::AUTO;
    }
}
