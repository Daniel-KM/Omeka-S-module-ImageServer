<?php declare(strict_types=1);

namespace ImageServerTest;

use DanielKm\Deepzoom\Deepzoom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the updated DanielKm\Deepzoom library.
 *
 * Verifies the key changes in the latest version:
 * - "magick" preferred over "convert" in getConvertPath()
 * - escapeshellarg() on convertPath/vipsPath in commands
 * - realpath() validation in process()
 * - PhpVips processor support in constructor
 */
class DeepzoomLibraryTest extends TestCase
{
    /**
     * getConvertPath() should return a non-empty string when magick
     * or convert is installed.
     */
    public function testGetConvertPathFindsCommand(): void
    {
        $deepzoom = new Deepzoom([
            'processor' => 'ImageMagick',
        ]);
        $path = $deepzoom->getConvertPath();
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

        $deepzoom = new Deepzoom([
            'processor' => 'ImageMagick',
        ]);
        $path = $deepzoom->getConvertPath();
        $this->assertStringContainsString(
            'magick',
            $path,
            'getConvertPath() should prefer magick over convert'
        );
    }

    /**
     * When convertPath is explicitly set, getConvertPath() returns it as-is.
     */
    public function testGetConvertPathRespectsExplicitConfig(): void
    {
        $deepzoom = new Deepzoom([
            'processor' => 'ImageMagick',
            'convertPath' => '/usr/bin/magick',
        ]);
        $this->assertSame('/usr/bin/magick', $deepzoom->getConvertPath());
    }

    /**
     * getVipsPath() should use "command -v vips" (not whereis).
     */
    public function testGetVipsPathFindsCommand(): void
    {
        $vipsAvailable = !empty(trim((string) shell_exec('command -v vips 2>/dev/null')));
        if (!$vipsAvailable) {
            $this->markTestSkipped('vips command not available.');
        }

        $deepzoom = new Deepzoom([
            'processor' => 'Vips',
        ]);
        $path = $deepzoom->getVipsPath();
        $this->assertNotEmpty($path);
        $this->assertStringContainsString('vips', $path);
    }

    /**
     * process() should throw when the source file does not exist
     * (realpath() validation).
     */
    public function testProcessThrowsOnNonExistentFile(): void
    {
        $deepzoom = new Deepzoom([
            'processor' => 'ImageMagick',
        ]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Source file does not exist');
        $deepzoom->process('/non/existent/file.jpg', sys_get_temp_dir() . '/dz_test_out');
    }

    /**
     * Constructor accepts "ImageMagick" processor when magick/convert
     * is available.
     */
    public function testConstructorImageMagickProcessor(): void
    {
        $deepzoom = new Deepzoom([
            'processor' => 'ImageMagick',
        ]);
        $this->assertNotEmpty($deepzoom->getConvertPath());
    }

    /**
     * Constructor throws when "ImageMagick" is requested but
     * convertPath is forced empty.
     */
    public function testConstructorThrowsWhenConvertNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Convert path is not available');
        new Deepzoom([
            'processor' => 'ImageMagick',
            'convertPath' => '',
        ]);
    }

    /**
     * Constructor should accept "PhpVips" when the extension is loaded.
     */
    public function testConstructorPhpVipsProcessor(): void
    {
        $hasPhpVips = (extension_loaded('vips') || extension_loaded('ffi'))
            && class_exists('Jcupitt\Vips\Image');
        if (!$hasPhpVips) {
            $this->markTestSkipped('php-vips not available.');
        }

        $deepzoom = new Deepzoom([
            'processor' => 'PhpVips',
        ]);
        $this->assertInstanceOf(Deepzoom::class, $deepzoom);
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
        new Deepzoom([
            'processor' => 'PhpVips',
        ]);
    }

    /**
     * Constructor auto-detects a processor without explicit config.
     */
    public function testConstructorAutoDetectsProcessor(): void
    {
        $deepzoom = new Deepzoom();
        $this->assertInstanceOf(Deepzoom::class, $deepzoom);
    }

    /**
     * Verify the convert() method escapes convertPath with
     * escapeshellarg() by checking the class source directly.
     *
     * This is a structural test: the updated library must use
     * escapeshellarg($this->convertPath) in the convert() method.
     */
    public function testConvertMethodEscapesPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/deepzoom/src/Deepzoom.php'
        );
        $this->assertStringContainsString(
            'escapeshellarg($this->convertPath)',
            $source,
            'convert() must use escapeshellarg() on convertPath'
        );
    }

    /**
     * Verify the processVips() method escapes vipsPath.
     */
    public function testProcessVipsEscapesPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/deepzoom/src/Deepzoom.php'
        );
        $this->assertStringContainsString(
            'escapeshellarg($this->vipsPath)',
            $source,
            'processVips() must use escapeshellarg() on vipsPath'
        );
    }

    /**
     * Verify getConvertPath() uses "command -v" not "whereis".
     */
    public function testGetConvertPathUsesCommandV(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/daniel-km/deepzoom/src/Deepzoom.php'
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
}
