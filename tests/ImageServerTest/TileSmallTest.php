<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;
use ImageServer\Service\ControllerPlugin\TilerFactory;

/**
 * Tests for the tiling of only the images bigger than the max size of the images
 * processed dynamically, unless the option to tile small images too is set.
 *
 * The fixture test-exif6.jpg is 100x60 pixels (6000 pixels).
 */
class TileSmallTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    /**
     * @var array
     */
    protected $settingsBackup = [];

    /**
     * @var string
     */
    protected $storageId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        foreach (['imageserver_image_max_size', 'imageserver_tile_small', 'imageserver_image_tile_type', 'imageserver_imager'] as $name) {
            $this->settingsBackup[$name] = $settings->get($name);
        }
        $settings->set('imageserver_image_tile_type', 'deepzoom');
        $settings->set('imageserver_imager', 'Auto');
    }

    public function tearDown(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        foreach ($this->settingsBackup as $name => $value) {
            $settings->set($name, $value);
        }
        if ($this->storageId) {
            $basePath = $this->basePath();
            @unlink($basePath . '/original/' . $this->storageId);
            @unlink($basePath . '/tile/' . $this->storageId . '.dzi');
            $this->removeDir($basePath . '/tile/' . $this->storageId . '_files');
        }
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testSmallImageIsNotTiledByDefault(): void
    {
        $result = $this->tile(10000, false);
        $this->assertTrue($result['result']);
        $this->assertTrue($result['skipped']);
        $this->assertNull($result['tile_file']);
        $this->assertFileDoesNotExist($this->basePath() . '/tile/' . $this->storageId . '.dzi');
    }

    public function testLargeImageIsTiledByDefault(): void
    {
        $result = $this->tile(1000, false);
        $this->assertTrue($result['result']);
        $this->assertFileExists($result['tile_file']);
    }

    public function testSmallImageIsTiledWithOption(): void
    {
        $result = $this->tile(10000, true);
        $this->assertTrue($result['result']);
        $this->assertFileExists($result['tile_file']);
    }

    public function testImageIsTiledWithoutMaxSize(): void
    {
        $result = $this->tile(0, false);
        $this->assertTrue($result['result']);
        $this->assertFileExists($result['tile_file']);
    }

    protected function tile(int $maxSize, bool $tileSmall): array
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $settings->set('imageserver_image_max_size', $maxSize);
        $settings->set('imageserver_tile_small', $tileSmall);

        $item = $this->createItem();
        $mediaId = $this->insertMediaRow($item->id(), 'upload', 'file', 'image/jpeg', [
            'dimensions' => ['original' => ['width' => 100, 'height' => 60]],
        ]);
        $connection = $this->getConnection();
        $connection->executeStatement('UPDATE `media` SET `has_original` = 1 WHERE `id` = ?', [$mediaId]);
        $this->storageId = (string) $connection->fetchOne('SELECT `storage_id` FROM `media` WHERE `id` = ?', [$mediaId]);
        copy(__DIR__ . '/fixtures/test-exif6.jpg', $this->basePath() . '/original/' . $this->storageId);

        $this->getEntityManager()->clear();
        $media = $this->api()->read('media', $mediaId)->getContent();
        $tiler = (new TilerFactory())($services, 'tiler');
        $tiler->setController($services->get('ControllerManager')->get(\Omeka\Controller\IndexController::class));
        return $tiler($media, true);
    }

    protected function basePath(): string
    {
        $config = $this->getServiceLocator()->get('Config');
        return $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
