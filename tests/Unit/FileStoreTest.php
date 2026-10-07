<?php

declare(strict_types=1);

namespace VuFindConfigGui\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VuFindConfigGui\ConflictException;
use VuFindConfigGui\FileStore;
use VuFindConfigGui\HttpException;
use VuFindConfigGui\Instance;

final class FileStoreTest extends TestCase
{
    private string $dir;
    private Instance $inst;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vcg-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/home/config/vufind', 0777, true);
        mkdir($this->dir . '/local/cache/configs', 0777, true);
        file_put_contents($this->dir . '/home/config/vufind/config.ini', "[Catalog]\ndriver = Folio\n");
        file_put_contents($this->dir . '/home/config/vufind/Folio.ini', "[API]\nbase_url = x\n");
        file_put_contents($this->dir . '/home/config/vufind/searches.ini', "[General]\ndefault_limit = 20\n");
        file_put_contents($this->dir . '/local/cache/configs/cached.php', '<?php');
        $this->inst = Instance::fromArray('t', ['home' => $this->dir . '/home', 'local' => $this->dir . '/local']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public static function badPaths(): array
    {
        return [['../etc/passwd'], ['config/vufind/../../x.ini'], ['/etc/x.ini'], ['config/vufind/x.php'], ['themes/x.ini'], ['config/vufind//x.ini']];
    }

    #[DataProvider('badPaths')]
    public function testRejectsBadPaths(string $rel): void
    {
        $this->expectException(HttpException::class);
        FileStore::checkRel($rel);
    }

    public function testAcceptsNestedConfig(): void
    {
        $this->assertSame('config/vufind/RecordDataFormatter/DefaultRecord.ini', FileStore::checkRel('config/vufind/RecordDataFormatter/DefaultRecord.ini'));
    }

    public function testIlsDriverFileIsCurated(): void
    {
        $store = new FileStore($this->inst);
        $this->assertArrayHasKey('config/vufind/Folio.ini', $store->curated());
        $this->assertNotContains('config/vufind/Folio.ini', $store->otherFiles());
    }

    public function testEnsureLocalBackupAndCache(): void
    {
        $store = new FileStore($this->inst);
        $rel = 'config/vufind/searches.ini';
        $this->assertNull($store->backup($rel), 'nothing to back up without a local copy');
        $this->assertTrue($store->ensureLocal($rel));
        $this->assertFalse($store->ensureLocal($rel));
        $this->assertFileEquals($this->inst->origPath($rel), $this->inst->localPath($rel));

        $b1 = $store->backup($rel);
        $b2 = $store->backup($rel);
        $this->assertNotSame($b1, $b2, 'two backups in the same second must not overwrite each other');
        $this->assertFileExists($this->inst->backupDir . '/' . $b1);

        $this->assertSame(['configs' => 1], $store->clearCaches());
    }

    public function testWriteKeepsFileMode(): void
    {
        $store = new FileStore($this->inst);
        $rel = 'config/vufind/searches.ini';
        $store->ensureLocal($rel);
        chmod($this->inst->localPath($rel), 0640);
        $store->write($rel, "x = 1\n");
        clearstatcache();
        $this->assertSame(0640, fileperms($this->inst->localPath($rel)) & 0777);
    }

    public function testConflictDetection(): void
    {
        $store = new FileStore($this->inst);
        $rel = 'config/vufind/searches.ini';
        $store->ensureLocal($rel);
        $mtime = $store->mtime($rel);
        $store->assertUnchanged($rel, $mtime);
        $this->expectException(ConflictException::class);
        $store->assertUnchanged($rel, $mtime - 10);
    }

    public function testDiff(): void
    {
        $store = new FileStore($this->inst);
        $rel = 'config/vufind/searches.ini';
        $this->assertSame('', $store->diff($rel));
        $store->write($rel, "[General]\ndefault_limit = 50\n");
        $diff = $store->diff($rel);
        if ($diff === null) {
            $this->markTestSkipped('diff binary not available');
        }
        $this->assertStringContainsString('+default_limit = 50', $diff);
        $this->assertStringContainsString('--- original/' . $rel, $diff);
    }
}
