<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * The base of the tiles depends on the server and the store, so it is not
 * stored with the media, but set when the tile data are read.
 */
class TileBaseDataTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testWithoutBaseRemovesServerKeys(): void
    {
        $tileInfo = $this->getServiceLocator()->get('ControllerPluginManager')->get('tileInfo');
        $result = $tileInfo->withoutBase([
            'tile_type' => 'deepzoom',
            'url_base' => 'http://localhost/files/tile',
            'path_base' => '/var/www/files/tile',
            'url_query' => '',
            'size' => 256,
        ]);
        $this->assertSame(['tile_type' => 'deepzoom', 'size' => 256], $result);
    }

    public function testStoredBaseIsReplacedByCurrentOne(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow((int) $item->id(), 'upload', 'tile', 'image/jpeg', [
            'tile' => [
                'deepzoom' => [
                    'tile_type' => 'deepzoom',
                    'metadata_path' => 'abc.dzi',
                    'media_path' => 'abc_files',
                    'url_base' => 'http://localhost/files/tile',
                    'size' => 256,
                ],
            ],
        ]);
        $media = $this->api()->read('media', $mediaId)->getContent();

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $expected = $plugins->get('tileInfo')->withBase([])['url_base'];
        $tilingData = $plugins->get('tileMediaInfo')->__invoke($media);

        $this->assertSame($expected, $tilingData['url_base']);
        $this->assertArrayHasKey('path_base', $tilingData);
        $this->assertSame(256, $tilingData['size']);
    }

    public function testUpgradeRemovesStoredBase(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow((int) $item->id(), 'upload', 'tile', 'image/jpeg', [
            'dimensions' => ['original' => ['width' => 10, 'height' => 20]],
            'tile' => [
                'deepzoom' => [
                    'tile_type' => 'deepzoom',
                    'url_base' => 'http://localhost/files/tile',
                    'path_base' => '/var/www/files/tile',
                    'url_query' => '',
                    'size' => 256,
                ],
            ],
        ]);

        $services = $this->getApplicationServiceLocator();
        $module = $services->get('ModuleManager')->getModule('ImageServer');
        $module->setServiceLocator($services);
        $module->upgrade('3.6.29', '3.6.30', $services);

        $this->assertSame([
            'dimensions' => ['original' => ['width' => 10, 'height' => 20]],
            'tile' => ['deepzoom' => ['tile_type' => 'deepzoom', 'size' => 256]],
        ], $this->readMediaData($mediaId));
    }
}
