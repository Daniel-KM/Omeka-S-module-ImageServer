<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for the upgrade script.
 *
 * Verifies that the v3.6.22 migration correctly converts legacy "tile" and
 * "file" ingesters to "upload", and "tile" renderer to "file", and that the
 * v3.6.27 migration converts the max size once.
 */
class UpgradeTest extends AbstractHttpControllerTestCase
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

    /**
     * Run the ingester/renderer conversion SQL (same as upgrade 3.6.22).
     */
    protected function runUpgradeConversion(): void
    {
        $connection = $this->getConnection();
        $sql = <<<'SQL'
            UPDATE `media`
            SET `ingester` = "upload"
            WHERE `ingester` IN ("tile", "file")
            SQL;
        $connection->executeStatement($sql);
        $sql = <<<'SQL'
            UPDATE `media`
            SET `renderer` = "file"
            WHERE `renderer` = "tile"
            SQL;
        $connection->executeStatement($sql);
    }

    public function testTileIngesterConvertedToUpload(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'tile',
            'tile'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('upload', $row['ingester']);
    }

    public function testTileRendererConvertedToFile(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'tile',
            'tile'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('file', $row['renderer']);
    }

    public function testFileIngesterConvertedToUpload(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'file',
            'file'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('upload', $row['ingester']);
    }

    public function testFileRendererUnchanged(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'file',
            'file'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('file', $row['renderer']);
    }

    public function testUploadIngesterUnchanged(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('upload', $row['ingester']);
    }

    public function testUrlIngesterUnchanged(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'url',
            'file'
        );

        $this->runUpgradeConversion();

        $row = $this->readMediaIngesterRenderer($mediaId);
        $this->assertSame('url', $row['ingester']);
    }

    public function testMultipleMediaConverted(): void
    {
        $item = $this->createItem();
        $ids = [];

        // tile/tile -> upload/file.
        $ids[] = $this->insertMediaRow(
            $item->id(),
            'tile',
            'tile'
        );
        // file/file -> upload/file (renderer unchanged).
        $ids[] = $this->insertMediaRow(
            $item->id(),
            'file',
            'file'
        );
        // upload/file -> unchanged.
        $ids[] = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file'
        );

        $this->runUpgradeConversion();

        $expected = [
            ['ingester' => 'upload', 'renderer' => 'file'],
            ['ingester' => 'upload', 'renderer' => 'file'],
            ['ingester' => 'upload', 'renderer' => 'file'],
        ];
        foreach ($ids as $i => $mediaId) {
            $row = $this->readMediaIngesterRenderer($mediaId);
            $this->assertSame(
                $expected[$i]['ingester'],
                $row['ingester'],
                "Media $i ingester mismatch"
            );
            $this->assertSame(
                $expected[$i]['renderer'],
                $row['renderer'],
                "Media $i renderer mismatch"
            );
        }
    }

    /**
     * Run the real upgrade from 3.6.26 with some settings.
     */
    protected function upgradeFrom3626(array $settings): \Omeka\Settings\Settings
    {
        $services = $this->getApplicationServiceLocator();
        $omekaSettings = $services->get('Omeka\Settings');
        foreach ($settings as $name => $value) {
            $value === null
                ? $omekaSettings->delete($name)
                : $omekaSettings->set($name, $value);
        }
        $module = $services->get('ModuleManager')->getModule('ImageServer');
        $module->setServiceLocator($services);
        $module->upgrade('3.6.26', '3.6.27', $services);
        return $omekaSettings;
    }

    public function testMaxSizeConvertedOnceWhenReplayed(): void
    {
        $settings = $this->upgradeFrom3626([
            'imageserver_imager' => 'GD',
            'imageserver_image_max_size' => 5000000,
            'imageserver_tile_small' => null,
        ]);
        $this->assertSame(12500000, (int) $settings->get('imageserver_image_max_size'));
        $this->assertTrue($settings->get('imageserver_tile_small'));

        // A replayed upgrade does not convert the value again.
        $this->upgradeFrom3626([]);
        $this->assertSame(12500000, (int) $settings->get('imageserver_image_max_size'));
    }

    public function testMaxSizeRemovedWithVips(): void
    {
        $settings = $this->upgradeFrom3626([
            'imageserver_imager' => 'Vips',
            'imageserver_image_max_size' => 5000000,
            'imageserver_tile_small' => null,
        ]);
        $this->assertSame('', $settings->get('imageserver_image_max_size'));
        $this->assertFalse($settings->get('imageserver_tile_small'));
    }

    public function testMaxSizeKeptWhenSavedWithNewForm(): void
    {
        $settings = $this->upgradeFrom3626([
            'imageserver_imager' => 'GD',
            'imageserver_image_max_size' => 4000000,
            'imageserver_tile_small' => false,
        ]);
        $this->assertSame(4000000, (int) $settings->get('imageserver_image_max_size'));
        $this->assertFalse($settings->get('imageserver_tile_small'));
    }
}
