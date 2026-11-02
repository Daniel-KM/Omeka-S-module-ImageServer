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

    protected function imageSize(): \ImageServer\Mvc\Controller\Plugin\ImageSize
    {
        return $this->getServiceLocator()->get('ControllerPluginManager')->get('imageSize');
    }

    /**
     * ImageSize::getWidthAndHeightLocal() must return EXIF-oriented
     * dimensions (60x100) for a 100x60 image with EXIF orientation 6.
     */
    public function testImageSizeLocalReturnsOrientedDimensions(): void
    {
        $method = new \ReflectionMethod($this->imageSize(), 'getWidthAndHeightLocal');
        $method->setAccessible(true);

        $result = $method->invoke($this->imageSize(), $this->fixtureExif6Path());

        $this->assertSame(60, $result['width'],
            'Width should be 60 after EXIF auto-orient');
        $this->assertSame(100, $result['height'],
            'Height should be 100 after EXIF auto-orient');
    }

    /**
     * ImageSize::getWidthAndHeight() must return EXIF-oriented
     * dimensions when given a local path.
     */
    public function testImageSizeGetWidthAndHeightLocal(): void
    {
        $method = new \ReflectionMethod($this->imageSize(), 'getWidthAndHeight');
        $method->setAccessible(true);

        $result = $method->invoke($this->imageSize(), $this->fixtureExif6Path());

        $this->assertSame(60, $result['width']);
        $this->assertSame(100, $result['height']);
    }

    /**
     * Verify that a normal image (no EXIF rotation) still returns
     * the correct raw dimensions.
     */
    public function testImageSizeLocalNormalImageUnchanged(): void
    {
        // Create a minimal 2x3 GIF (no EXIF).
        $tmpFile = tempnam(sys_get_temp_dir(), 'iiif_test_');
        $img = imagecreatetruecolor(2, 3);
        imagejpeg($img, $tmpFile);
        imagedestroy($img);

        $method = new \ReflectionMethod($this->imageSize(), 'getWidthAndHeightLocal');
        $method->setAccessible(true);
        $result = $method->invoke($this->imageSize(), $tmpFile);

        unlink($tmpFile);

        $this->assertSame(2, $result['width']);
        $this->assertSame(3, $result['height']);
    }

    /**
     * PhpVips: a dynamic size of a jpeg with an EXIF orientation must be
     * rotated, not fail with an "out of order read" of the sequential load.
     *
     * The failure occurs only with a big image upside down (orientation 3).
     *
     * @see https://github.com/Daniel-KM/Omeka-S-module-ImageServer/issues/13
     */
    public function testPhpVipsTransformExifOrientation(): void
    {
        $services = $this->getServiceLocator();
        $imager = new \ImageServer\ImageServer\PhpVips(
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Store')
        );
        if (!$imager->checkMediaType('image/jpeg')) {
            $this->markTestSkipped('php-vips not available.');
        }
        $imager->setLogger($services->get('Omeka\Logger'));

        $dir = $this->getTempPath();
        mkdir($dir, 0775, true);
        $bigUpsideDown = $dir . '/exif3.jpg';
        $image = \Jcupitt\Vips\Image::xyz(5184, 3888)->extract_band(0)->cast('uchar')
            ->bandjoin([128, 64])
            ->copy(['interpretation' => 'srgb']);
        $image->set('orientation', 3);
        $image->writeToFile($bigUpsideDown);

        $sources = [
            [$this->fixtureExif6Path(), 30, 50],
            [$bigUpsideDown, 1000, 750],
        ];
        foreach ($sources as [$source, $expectedWidth, $expectedHeight]) {
            $destination = $imager->transform([
                'version' => '3',
                'source' => ['filepath' => $source, 'media_type' => 'image/jpeg'],
                'region' => ['feature' => 'full'],
                'size' => ['feature' => 'sizeByForcedWh', 'width' => $expectedWidth, 'height' => $expectedHeight],
                'mirror' => ['feature' => 'default'],
                'rotation' => ['feature' => 'noRotation'],
                'quality' => ['feature' => 'default'],
                'format' => ['feature' => 'image/jpeg'],
            ]);
            $this->assertNotNull($destination, basename($source));
            $this->assertFileExists($destination);
            [$width, $height] = getimagesize($destination);
            @unlink($destination);
            $this->assertSame([$expectedWidth, $expectedHeight], [$width, $height], basename($source));
        }
    }
}
