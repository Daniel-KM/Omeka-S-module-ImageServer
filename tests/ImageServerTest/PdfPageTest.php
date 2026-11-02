<?php declare(strict_types=1);

namespace ImageServerTest;

use Common\Stdlib\FileLock;
use ImageServer\Mvc\Controller\Plugin\PdfPage;
use PHPUnit\Framework\TestCase;

class PdfPageTest extends TestCase
{
    public function identifierProvider(): array
    {
        return [
            ['file.pdf;2', ['file.pdf', 2]],
            ['12;3', ['12', 3]],
            ['dir/file.pdf;10', ['dir/file.pdf', 10]],
            ['file.pdf', ['file.pdf', 0]],
            ['file.pdf;0', ['file.pdf;0', 0]],
            ['file;name.jpg', ['file;name.jpg', 0]],
        ];
    }

    /**
     * @dataProvider identifierProvider
     */
    public function testParseIdentifier(string $identifier, array $expected): void
    {
        $this->assertSame($expected, PdfPage::parseIdentifier($identifier));
    }

    public function testFilepath(): void
    {
        $this->assertSame('/files/tile/pdf/abc/2.jpg', PdfPage::filepath('/files', 'tile', 'abc', 2));
    }

    public function testRenderCommandStartsPagesFromZero(): void
    {
        $this->assertSame(
            "/usr/bin/vips copy '/files/original/abc.pdf[page=1,dpi=150]' '/tmp/2.jpg[Q=90,strip]'",
            PdfPage::renderCommand('/usr/bin/vips', '/files/original/abc.pdf', 2, 150, '/tmp/2.jpg')
        );
    }

    protected function tempDirpath(): string
    {
        $dirpath = sys_get_temp_dir() . '/imageserver_test_' . uniqid();
        mkdir($dirpath);
        return $dirpath;
    }

    protected function removeDir(string $dirpath): void
    {
        array_map('unlink', glob($dirpath . '/*') ?: []);
        rmdir($dirpath);
    }

    /**
     * Hold the lock like another process: a flock is set by open file.
     *
     * @return resource
     */
    protected function lockByOther(string $filepath)
    {
        $lockFilepath = (new FileLock())->lockFilepath($filepath);
        $handle = fopen($lockFilepath, 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        return $handle;
    }

    public function testLockOrWaitAcquiresWhenFileIsMissing(): void
    {
        $dirpath = $this->tempDirpath();
        $filepath = $dirpath . '/1.jpg';
        $fileLock = new FileLock();
        $this->assertTrue(PdfPage::lockOrWait($fileLock, $filepath, 1));
        $this->assertTrue($fileLock->isLocked($filepath));
        $fileLock->release($filepath);
        $this->removeDir($dirpath);
    }

    public function testLockOrWaitSkipsExistingFile(): void
    {
        $dirpath = $this->tempDirpath();
        $filepath = $dirpath . '/1.jpg';
        file_put_contents($filepath, 'jpg');
        $fileLock = new FileLock();
        $this->assertFalse(PdfPage::lockOrWait($fileLock, $filepath, 1));
        $this->assertFalse($fileLock->isLocked($filepath));
        $this->removeDir($dirpath);
    }

    public function testLockOrWaitReturnsTheFileBuiltByAnotherProcess(): void
    {
        $dirpath = $this->tempDirpath();
        $filepath = $dirpath . '/1.jpg';
        $handle = $this->lockByOther($filepath);
        file_put_contents($filepath, 'jpg');
        $start = microtime(true);
        $this->assertFalse(PdfPage::lockOrWait(new FileLock(), $filepath, 5));
        $this->assertLessThan(1, microtime(true) - $start);
        fclose($handle);
        $this->removeDir($dirpath);
    }

    public function testLockOrWaitStopsAfterTimeoutWhenLocked(): void
    {
        $dirpath = $this->tempDirpath();
        $filepath = $dirpath . '/1.jpg';
        $handle = $this->lockByOther($filepath);
        $start = microtime(true);
        $this->assertFalse(PdfPage::lockOrWait(new FileLock(), $filepath, 1));
        $this->assertGreaterThanOrEqual(1, microtime(true) - $start);
        $this->assertFileDoesNotExist($filepath);
        fclose($handle);
        $this->removeDir($dirpath);
    }
}
