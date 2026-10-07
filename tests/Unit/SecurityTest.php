<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VuFindConfigGui\Security;
use VuFindConfigGui\Settings;

final class SecurityTest extends TestCase
{
    private static function settings(array $extra = []): Settings
    {
        return Settings::fromArray($extra + ['instances' => ['a' => ['home' => '/tmp']]]);
    }

    public static function hosts(): array
    {
        return [
            ['localhost', true],
            ['localhost:8181', true],
            ['127.0.0.1:8181', true],
            ['[::1]:8181', true],
            ['LOCALHOST', true],
            ['evil.example', false],
            ['localhost.evil.example', false],
            ['', false],
        ];
    }

    #[DataProvider('hosts')]
    public function testDefaultHostAllowList(string $host, bool $ok): void
    {
        $this->assertSame($ok, (new Security(self::settings(), ['HTTP_HOST' => $host]))->hostAllowed());
    }

    public function testWildcardDisablesHostCheck(): void
    {
        $this->assertTrue((new Security(self::settings(['allowed_hosts' => ['*']]), ['HTTP_HOST' => 'x']))->hostAllowed());
    }

    public function testApiNeedsCustomHeader(): void
    {
        $s = self::settings();
        $this->assertFalse((new Security($s, ['REQUEST_METHOD' => 'GET']))->apiRequestAllowed());
        $this->assertTrue((new Security($s, ['REQUEST_METHOD' => 'GET', Security::API_HEADER => '1']))->apiRequestAllowed());
    }

    public function testCrossSiteRequestIsRejected(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', Security::API_HEADER => '1', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
        $this->assertFalse((new Security(self::settings(), $server))->apiRequestAllowed());
    }

    public function testPostNeedsJson(): void
    {
        $base = ['REQUEST_METHOD' => 'POST', Security::API_HEADER => '1'];
        $this->assertFalse((new Security(self::settings(), $base + ['CONTENT_TYPE' => 'text/plain']))->apiRequestAllowed());
        $this->assertTrue((new Security(self::settings(), $base + ['CONTENT_TYPE' => 'application/json; charset=utf-8']))->apiRequestAllowed());
    }

    public function testBasicAuth(): void
    {
        $s = self::settings(['auth' => ['user' => 'admin', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT)]]);
        $this->assertFalse((new Security($s, []))->authenticated());
        $this->assertFalse((new Security($s, ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'wrong']))->authenticated());
        $this->assertTrue((new Security($s, ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'secret']))->authenticated());
        $this->assertTrue((new Security(self::settings(), []))->authenticated());
    }
}
