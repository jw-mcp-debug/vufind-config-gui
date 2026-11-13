<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VuFindConfigGui\Theme;

final class ThemeTest extends TestCase
{
    public function testCookieValues(): void
    {
        $this->assertSame('dark', Theme::fromCookie('dark'));
        $this->assertSame('light', Theme::fromCookie('light'));
        $this->assertSame('auto', Theme::fromCookie(null));
        $this->assertSame('auto', Theme::fromCookie('"><script>'));
    }
}
