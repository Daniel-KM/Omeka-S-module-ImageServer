<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for the tileArgs data flow in the Tiler race condition fix.
 *
 * The Tile ingester stores tileArgs in media data during hydration.
 * Module::afterSaveMedia() detects them post-commit and dispatches
 * the Tiler job with a mediaId argument.
 * Tiler::endJob() cleans tileArgs from media data after tiling.
 *
 * These tests verify the data storage and cleanup logic via direct
 * SQL, without requiring actual file uploads or tile generation.
 */
class TileArgsDataTest extends AbstractHttpControllerTestCase
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
     * Simulate the Tiler::endJob() cleanup of tileArgs from media
     * data. This replicates the SQL logic in Tiler.php (new path).
     */
    protected function simulateEndJobCleanup(int $mediaId): void
    {
        $connection = $this->getConnection();
        $sql = "SELECT `data` FROM `media` WHERE `id` = $mediaId";
        $raw = $connection->fetchOne($sql);
        if ($raw) {
            $data = json_decode($raw, true);
            unset($data['tileArgs']);
            $newData = $data
                ? $connection->quote(json_encode(
                    $data,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ))
                : 'NULL';
        } else {
            $newData = 'NULL';
        }
        $sql = "UPDATE `media` SET `data` = $newData"
            . " WHERE `id` = $mediaId";
        $connection->executeStatement($sql);
    }

    /**
     * Simulate the legacy Tiler::endJob() cleanup (job key via
     * LIKE/REPLACE pattern).
     */
    protected function simulateLegacyEndJobCleanup(
        int $mediaId,
        int $jobId
    ): void {
        $connection = $this->getConnection();
        $sql = <<<SQL
            UPDATE `media`
            SET
            `data` = CASE
                WHEN `data` = '{"job":$jobId}' THEN NULL
                WHEN `data` LIKE '%"job":$jobId,%'
                    THEN REPLACE(`data`, '"job":$jobId,', '')
                WHEN `data` LIKE '%"job":$jobId}%'
                    THEN REPLACE(`data`, '"job":$jobId}', '')
                ELSE `data`
                END
            WHERE `id` = $mediaId
            SQL;
        $connection->executeStatement($sql);
    }

    public function testTileArgsStoredInMediaData(): void
    {
        $item = $this->createItem();
        $tileArgs = [
            'storageId' => 'abc123',
            'storagePath' => '/files/original/abc123.jpg',
            'storeOriginal' => true,
            'type' => 'deepzoom',
        ];
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            ['tileArgs' => $tileArgs]
        );

        $data = $this->readMediaData($mediaId);
        $this->assertArrayHasKey('tileArgs', $data);
        $this->assertSame(
            'abc123',
            $data['tileArgs']['storageId']
        );
        $this->assertSame(
            '/files/original/abc123.jpg',
            $data['tileArgs']['storagePath']
        );
        $this->assertTrue($data['tileArgs']['storeOriginal']);
        $this->assertSame('deepzoom', $data['tileArgs']['type']);
    }

    public function testEndJobCleanupRemovesTileArgs(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            ['tileArgs' => [
                'storageId' => 'abc123',
                'storagePath' => '/files/original/abc123.jpg',
                'storeOriginal' => true,
                'type' => 'deepzoom',
            ]]
        );

        $this->simulateEndJobCleanup($mediaId);

        $data = $this->readMediaData($mediaId);
        $this->assertNull(
            $data,
            'Data should be null after cleanup'
        );
    }

    public function testEndJobCleanupPreservesOtherData(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            [
                'tileArgs' => [
                    'storageId' => 'abc123',
                    'storagePath' => '/files/original/abc123.jpg',
                    'storeOriginal' => true,
                    'type' => 'deepzoom',
                ],
                'dimensions' => [
                    'original' => [
                        'width' => 4000,
                        'height' => 3000,
                    ],
                    'large' => [
                        'width' => 800,
                        'height' => 600,
                    ],
                ],
            ]
        );

        $this->simulateEndJobCleanup($mediaId);

        $data = $this->readMediaData($mediaId);
        $this->assertNotNull($data);
        $this->assertArrayNotHasKey(
            'tileArgs',
            $data,
            'tileArgs should be removed'
        );
        $this->assertArrayHasKey(
            'dimensions',
            $data,
            'dimensions should be preserved'
        );
        $this->assertSame(
            4000,
            $data['dimensions']['original']['width']
        );
    }

    public function testEndJobCleanupWithNullData(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            null
        );

        // Should not throw.
        $this->simulateEndJobCleanup($mediaId);

        $data = $this->readMediaData($mediaId);
        $this->assertNull($data);
    }

    public function testTileArgsWithDimensionsCoexist(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            [
                'tileArgs' => [
                    'storageId' => 'def456',
                    'storagePath' => '/files/original/def456.png',
                    'storeOriginal' => false,
                    'type' => 'zoomify',
                ],
                'dimensions' => [
                    'large' => [
                        'width' => 1024,
                        'height' => 768,
                    ],
                ],
            ]
        );

        $data = $this->readMediaData($mediaId);
        $this->assertArrayHasKey('tileArgs', $data);
        $this->assertArrayHasKey('dimensions', $data);
        $this->assertSame('zoomify', $data['tileArgs']['type']);
        $this->assertSame(
            1024,
            $data['dimensions']['large']['width']
        );
    }

    public function testLegacyJobKeyCleanup(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            ['job' => 42]
        );

        $this->simulateLegacyEndJobCleanup($mediaId, 42);

        $data = $this->readMediaData($mediaId);
        $this->assertNull(
            $data,
            'Data should be null after legacy cleanup'
        );
    }

    public function testLegacyJobKeyCleanupPreservesOtherData(): void
    {
        $item = $this->createItem();
        $mediaId = $this->insertMediaRow(
            $item->id(),
            'upload',
            'file',
            'image/jpeg',
            [
                'job' => 99,
                'dimensions' => [
                    'large' => [
                        'width' => 500,
                        'height' => 400,
                    ],
                ],
            ]
        );

        $this->simulateLegacyEndJobCleanup($mediaId, 99);

        $data = $this->readMediaData($mediaId);
        // The legacy cleanup uses REPLACE on string patterns.
        // With {"job":99,"dimensions":...}, removing "job":99,
        // leaves {"dimensions":...}.
        $this->assertNotNull($data);
        $this->assertArrayHasKey('dimensions', $data);
    }
}
