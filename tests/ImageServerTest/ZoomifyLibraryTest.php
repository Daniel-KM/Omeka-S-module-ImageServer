<?php declare(strict_types=1);

namespace ImageServerTest;

use DanielKm\Zoomify\Zoomify;
use DanielKm\Zoomify\ZoomifyImageMagick;
use DanielKm\Zoomify\ZoomifyVips;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the updated DanielKm\Zoomify library.
 *
 * Verifies the key changes in the latest version:
 * - "magick" preferred over "convert" in getConvertPath()
 * - escapeshellarg() on convertPath/vipsPath in commands
 * - realpath() validation in process()
 * - PhpVips processor support in constructor
 */
class ZoomifyLibraryTest extends TestCase
{
    /**
     * getConvertPath() should return a non-empty string when magick
     * or convert is installed.
     */
    public function testGetConvertPathFindsCommand(): void
    {
        $processor = new ZoomifyImageMagick();
        $path = $processor->getConvertPath();
        $this->assertNotEmpty($path, 'getConvertPath() should find magick or convert');
    }

    /**
     * getConvertPath() should prefer "magick" when available.
     */
    public function testGetConvertPathPrefersMagick(): void
    {
        $magickAvailable = !empty(trim((string) shell_exec('command -v magick 2>/dev/null')));
        if (!$magickAvailable) {
            $this->markTestSkipped('magick command not available.');
        }

        $processor = new ZoomifyImageMagick();
        $path = $processor->getConvertPath();
        $this->assertStringContainsString(
            'magick',
            $path,
            'getConvertPath() should prefer magick over convert'
        );
    }

    /**
     * When convertPath is explicitly set, getConvertPath() returns it.
     */
    public function testGetConvertPathRespectsExplicitConfig(): void
    {
        $processor = new ZoomifyImageMagick([
            'convertPath' => '/usr/bin/magick',
        ]);
        $this->assertSame('/usr/bin/magick', $processor->getConvertPath());
    }

    /**
     * getVipsPath() on ZoomifyVips should find the vips command.
     */
    public function testGetVipsPathFindsCommand(): void
    {
        $vipsAvailable = !empty(trim((string) shell_exec('command -v vips 2>/dev/null')));
        if (!$vipsAvailable) {
            $this->markTestSkipped('vips command not available.');
        }

        $processor = new ZoomifyVips();
        $path = $processor->getVipsPath();
        $this->assertNotEmpty($path);
        $this->assertStringContainsString('vips', $path);
    }

    /**
     * ZoomifyImageMagick::process() should throw when the source file
     * does not exist (realpath() validation).
     */
    public function testProcessThrowsOnNonExistentFile(): void
    {
        $processor = new ZoomifyImageMagick([
            'convertPath' => '/usr/bin/magick',
        ]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File does not exist');
        $processor->process('/non/existent/file.jpg', sys_get_temp_dir() . '/zm_test_out');
    }

    /**
     * ZoomifyVips::process() should throw when the source file does
     * not exist.
     */
    public function testVipsProcessThrowsOnNonExistentFile(): void
    {
        $vipsAvailable = !empty(trim((string) shell_exec('command -v vips 2>/dev/null')));
        if (!$vipsAvailable) {
            $this->markTestSkipped('vips command not available.');
        }

        $processor = new ZoomifyVips([
            'vipsPath' => trim((string) shell_exec('command -v vips')),
        ]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File does not exist');
        $processor->process('/non/existent/file.jpg', sys_get_temp_dir() . '/zm_vips_test_out');
    }

    /**
     * Zoomify main class auto-detects a processor.
     */
    public function testZoomifyAutoDetectsProcessor(): void
    {
        $zoomify = new Zoomify();
        $this->assertInstanceOf(Zoomify::class, $zoomify);
    }

    /**
     * Constructor accepts "ImageMagick" processor.
     */
    public function testConstructorImageMagickProcessor(): void
    {
        $zoomify = new Zoomify([
            'processor' => 'ImageMagick',
        ]);
        $this->assertInstanceOf(Zoomify::class, $zoomify);
    }

    /**
     * ZoomifyImageMagick::getConvertPath() returns empty string when
     * forced to an empty path.
     */
    public function testZoomifyImageMagickEmptyConvertPath(): void
    {
        $processor = new ZoomifyImageMagick([
            'convertPath' => '',
        ]);
        $this->assertSame('', $processor->getConvertPath());
    }

    /**
     * Constructor should support "PhpVips" processor.
     */
    public function testConstructorPhpVipsProcessor(): void
    {
        $hasPhpVips = (extension_loaded('vips') || extension_loaded('ffi'))
            && class_exists('Jcupitt\Vips\Image');
        if (!$hasPhpVips) {
            $this->markTestSkipped('php-vips not available.');
        }

        $zoomify = new Zoomify([
            'processor' => 'PhpVips',
        ]);
        $this->assertInstanceOf(Zoomify::class, $zoomify);
    }

    /**
     * Constructor throws for "PhpVips" when the extension is missing.
     */
    public function testConstructorPhpVipsThrowsWhenMissing(): void
    {
        $hasPhpVips = (extension_loaded('vips') || extension_loaded('ffi'))
            && class_exists('Jcupitt\Vips\Image');
        if ($hasPhpVips) {
            $this->markTestSkipped('php-vips is available, cannot test failure.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('php-vips library is not available');
        new Zoomify([
            'processor' => 'PhpVips',
        ]);
    }

    /**
     * Verify the convert() method in ZoomifyImageMagick escapes
     * convertPath with escapeshellarg().
     */
    public function testConvertMethodEscapesPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/zoomify/src/ZoomifyImageMagick.php'
        );
        // The convert() method must use escapeshellarg($this->convertPath).
        $this->assertStringContainsString(
            'escapeshellarg($this->convertPath)',
            $source,
            'convert() must use escapeshellarg() on convertPath'
        );
    }

    /**
     * Verify processRowImage() in ZoomifyImageMagick escapes
     * convertPath with escapeshellarg().
     */
    public function testProcessRowImageEscapesPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/zoomify/src/ZoomifyImageMagick.php'
        );
        // processRowImage() constructs commands with convertPath;
        // all usages must be escaped.
        $this->assertStringNotContainsString(
            '$this->convertPath,',
            $source,
            'convertPath must not be used unescaped (without escapeshellarg)'
        );
    }

    /**
     * Verify ZoomifyVips escapes vipsPath with escapeshellarg().
     */
    public function testVipsProcessEscapesPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/zoomify/src/ZoomifyVips.php'
        );
        $this->assertStringContainsString(
            'escapeshellarg($this->vipsPath)',
            $source,
            'ZoomifyVips::process() must use escapeshellarg() on vipsPath'
        );
    }

    /**
     * Verify getConvertPath() uses "command -v" not "whereis".
     */
    public function testGetConvertPathUsesCommandV(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/zoomify/src/ZoomifyImageMagick.php'
        );
        $this->assertStringContainsString(
            "command -v magick",
            $source,
            'getConvertPath() must try "command -v magick" first'
        );
        $this->assertStringContainsString(
            "command -v convert",
            $source,
            'getConvertPath() must fall back to "command -v convert"'
        );
        $this->assertStringNotContainsString(
            'whereis',
            $source,
            'getConvertPath() must not use deprecated "whereis"'
        );
    }

    /**
     * Verify getVipsPath() uses "command -v" not "whereis".
     */
    public function testGetVipsPathUsesCommandV(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/zoomify/src/ZoomifyVips.php'
        );
        $this->assertStringContainsString(
            "command -v vips",
            $source,
            'getVipsPath() must use "command -v vips"'
        );
        $this->assertStringNotContainsString(
            'whereis',
            $source,
            'getVipsPath() must not use deprecated "whereis"'
        );
    }
}
