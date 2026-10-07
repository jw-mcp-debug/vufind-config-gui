<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VuFindConfigGui\Settings;

final class SettingsTest extends TestCase
{
    public function testInstanceLookupFallsBackToFirst(): void
    {
        $s = Settings::fromArray(['instances' => [
            'a' => ['home' => '/a/'],
            'b' => ['home' => '/b', 'local' => '/data/b-local', 'mcp' => ['endpoint' => 'http://x/mcp']],
        ]]);
        $this->assertSame('a', $s->instance(null)->key);
        $this->assertSame('a', $s->instance('unknown')->key);
        $this->assertSame('b', $s->instance('b')->key);
        $this->assertSame('/a', $s->instance('a')->home);
        $this->assertSame('/a/local', $s->instance('a')->local);
        $this->assertSame('/a/local/gui-backups', $s->instance('a')->backupDir);
        $this->assertSame('/data/b-local', $s->instance('b')->local);
        $this->assertSame('GuiDisabled', $s->instance('b')->mcp['parked_key']);
        $this->assertSame('http://x/mcp', $s->instance('b')->mcp['public_url']);
        $this->assertNull($s->instance('a')->mcp);
    }

    public function testRejectsBadInstanceKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Settings::fromArray(['instances' => ['Bad Key' => ['home' => '/x']]]);
    }

    public function testNeedsAnInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Settings::fromArray([]);
    }
}
