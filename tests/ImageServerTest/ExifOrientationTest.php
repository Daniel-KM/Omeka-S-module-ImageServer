<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;
use DanielKm\Deepzoom\Deepzoom;
use DanielKm\Zoomify\Zoomify;

/**
 * EXIF orientation tests for the tiling libraries used by ImageServer.
 *
 * The fixture test-exif6.jpg is 100x60 raw pixels with EXIF
 * orientation 6 (90° CW). After auto-orient: 60x100.
 *
 * These tests verify the vendor libraries respect EXIF orientation
 * in the context of the ImageServer module.
 */
class ExifOrientationTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    /**
     * @var string[]
     */
    protected array $tempDirs = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTempDir($dir);
        }
        $this->tempDirs = [];
        $this->cleanupResources();
        parent::tearDown();
    }

    protected function fixtureExif6Path(): string
    {
        return __DIR__ . '/fixtures/test-exif6.jpg';
    }

    protected function getTempPath(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'imageserver_test_' . uniqid();
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path)
                ? $this->removeTempDir($path)
                : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Deepzoom: EXIF-6 image must produce oriented DZI metadata.
     */
    public function testDeepzoomExifOrientationWithGd(): void
    {
        $dest = $this->getTempPath();
        mkdir($dest, 0755, true);

        $dz = new Deepzoom(['processor' => 'GD']);
        $result = $dz->process($this->fixtureExif6Path(), $dest);
        $this->assertTrue($result);

        $dziFiles = glob($dest . '/*.dzi');
        $this->assertNotEmpty($dziFiles);

        $xml = simplexml_load_file($dziFiles[0]);
        $size = $xml->Size->attributes();
        $this->assertSame(60, (int) $size['Width'],
            'DZI width should be 60 after auto-orient');
        $this->assertSame(100, (int) $size['Height'],
            'DZI height should be 100 after auto-orient');
    }

    /**
     * Deepzoom: noRotate keeps raw dimensions.
     */
    public function testDeepzoomNoRotateKeepsRawDimensions(): void
    {
        $dest = $this->getTempPath();
        mkdir($dest, 0755, true);

        $dz = new Deepzoom([
            'processor' => 'GD',
            'noRotate' => true,
        ]);
        $result = $dz->process($this->fixtureExif6Path(), $dest);
        $this->assertTrue($result);

        $dziFiles = glob($dest . '/*.dzi');
        $this->assertNotEmpty($dziFiles);

        $xml = simplexml_load_file($dziFiles[0]);
        $size = $xml->Size->attributes();
        $this->assertSame(100, (int) $size['Width'],
            'DZI width should be 100 with noRotate');
        $this->assertSame(60, (int) $size['Height'],
            'DZI height should be 60 with noRotate');
    }

    /**
     * Zoomify: EXIF-6 image must produce oriented ImageProperties.
     */
    public function testZoomifyExifOrientationWithGd(): void
    {
        $dest = $this->getTempPath();

        $z = new Zoomify(['processor' => 'GD']);
        $result = $z->process($this->fixtureExif6Path(), $dest);
        $this->assertTrue($result);

        $xmlPath = $dest . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
        $this->assertFileExists($xmlPath);

        $xml = simplexml_load_file($xmlPath);
        $attrs = $xml->attributes();
        $this->assertSame(60, (int) $attrs['WIDTH'],
            'Zoomify WIDTH should be 60 after auto-orient');
        $this->assertSame(100, (int) $attrs['HEIGHT'],
            'Zoomify HEIGHT should be 100 after auto-orient');
    }

    /**
     * Zoomify: noRotate keeps raw dimensions.
     */
    public function testZoomifyNoRotateKeepsRawDimensions(): void
    {
        $dest = $this->getTempPath();

        $z = new Zoomify([
            'processor' => 'GD',
            'noRotate' => true,
        ]);
        $result = $z->process($this->fixtureExif6Path(), $dest);
        $this->assertTrue($result);

        $xmlPath = $dest . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
        $this->assertFileExists($xmlPath);

        $xml = simplexml_load_file($xmlPath);
        $attrs = $xml->attributes();
        $this->assertSame(100, (int) $attrs['WIDTH'],
            'Zoomify WIDTH should be 100 with noRotate');
        $this->assertSame(60, (int) $attrs['HEIGHT'],
            'Zoomify HEIGHT should be 60 with noRotate');
    }
}
