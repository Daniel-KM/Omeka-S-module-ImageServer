<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;
use ImageServer\ImageServer\GD;

/**
 * A fixed destination (tiled source) is replaced atomically, never removed
 * before the new image is ready.
 */
class ImagerDestinationTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    /**
     * @var string
     */
    protected $dirpath;

    public function setUp(): void
    {
        parent::setUp();
        $this->dirpath = sys_get_temp_dir() . '/imageserver_test_' . uniqid();
        mkdir($this->dirpath);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->dirpath . '/*') ?: []);
        rmdir($this->dirpath);
        parent::tearDown();
    }

    protected function imager(): GD
    {
        $services = $this->getServiceLocator();
        $imager = new GD(
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Store')
        );
        $imager->setLogger($services->get('Omeka\Logger'));
        return $imager;
    }

    protected function args(string $source, ?string $destination, int $width): array
    {
        $args = [
            'version' => '3',
            'source' => ['filepath' => $source, 'media_type' => 'image/png'],
            'region' => ['feature' => 'full'],
            'size' => ['feature' => 'sizeByForcedWh', 'width' => $width, 'height' => $width],
            'mirror' => ['feature' => 'default'],
            'rotation' => ['feature' => 'noRotation'],
            'quality' => ['feature' => 'default'],
            'format' => ['feature' => 'image/png'],
        ];
        if ($destination) {
            $args['destination'] = ['filepath' => $destination];
        }
        return $args;
    }

    protected function createSource(): string
    {
        $source = $this->dirpath . '/source.png';
        $image = imagecreatetruecolor(40, 40);
        imagepng($image, $source);
        imagedestroy($image);
        return $source;
    }

    public function testFixedDestinationIsReplacedWithoutTempFile(): void
    {
        $source = $this->createSource();
        $destination = $this->dirpath . '/tile.png';
        file_put_contents($destination, 'previous');
        $handle = fopen($destination, 'r');

        $result = $this->imager()->transform($this->args($source, $destination, 20));

        $this->assertSame($destination, $result);
        $this->assertSame([20, 20], array_slice(getimagesize($destination), 0, 2));
        // The previous file is replaced by a rename, not rewritten in place.
        $this->assertSame('previous', stream_get_contents($handle));
        fclose($handle);
        $this->assertSame(
            ['source.png', 'tile.png'],
            array_values(array_diff(scandir($this->dirpath), ['.', '..']))
        );
    }

    public function testFixedDestinationIsCreatedInMissingDirectory(): void
    {
        $source = $this->createSource();
        $destination = $this->dirpath . '/sub/tile.png';

        $result = $this->imager()->transform($this->args($source, $destination, 10));

        $this->assertSame($destination, $result);
        $this->assertSame(['tile.png'], array_values(array_diff(scandir(dirname($destination)), ['.', '..'])));
        unlink($destination);
        rmdir(dirname($destination));
    }

    public function testTempDestinationIsUnchanged(): void
    {
        $source = $this->createSource();

        $result = $this->imager()->transform($this->args($source, null, 10));

        $this->assertNotNull($result);
        $this->assertStringStartsNotWith($this->dirpath, $result);
        $this->assertFileExists($result);
        unlink($result);
    }
}
