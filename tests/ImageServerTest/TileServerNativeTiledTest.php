<?php declare(strict_types=1);

namespace ImageServerTest;

use ImageServer\Mvc\Controller\Plugin\TileServerNativeTiled;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TileServerNativeTiled.
 *
 * The plugin is instantiated directly (no controller needed) because
 * it only calls inherited math methods (getLevelAndPosition, etc.)
 * and never calls getController().
 *
 * Test fixtures: no real media files are needed. The tests verify the
 * calculation logic with simulated $tileInfo and $transform arrays
 * that mirror the structures produced by TileInfo::getTilingDataMedia()
 * and ImageController::cleanRequest().
 */
class TileServerNativeTiledTest extends TestCase
{
    /**
     * @var TileServerNativeTiled
     */
    protected $plugin;

    public function setUp(): void
    {
        $this->plugin = new TileServerNativeTiled();
    }

    /**
     * Build a tileInfo array mimicking TileInfo::getTilingDataMedia().
     */
    protected function makeTileInfo(
        string $tileType = 'tiled_tiff',
        int $sourceWidth = 1024,
        int $sourceHeight = 1024
    ): array {
        $format = $tileType === 'jpeg2000' ? 'jp2' : 'tif';
        $ext = $tileType === 'jpeg2000' ? '.jp2' : '.tif';
        return [
            'tile_type' => $tileType,
            'metadata_path' => null,
            'media_path' => 'abc123' . $ext,
            'url_base' => 'http://example.org/files/tile',
            'path_base' => '/var/www/files/tile',
            'url_query' => '',
            'size' => 256,
            'overlap' => 0,
            'total' => null,
            'source' => [
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ],
            'format' => $format,
        ];
    }

    /**
     * Build a transform array mimicking ImageController::cleanRequest().
     *
     * @param string $regionFeature 'full' or 'regionByPx'.
     * @param int[] $regionCoords [x, y, w, h] for regionByPx.
     * @param string $sizeFeature 'max', 'sizeByW', 'sizeByWh', etc.
     * @param int|null $sizeW Requested output width.
     * @param int|null $sizeH Requested output height.
     * @param int $sourceWidth Source image width.
     * @param int $sourceHeight Source image height.
     */
    protected function makeTransform(
        string $regionFeature = 'regionByPx',
        array $regionCoords = [0, 0, 256, 256],
        string $sizeFeature = 'max',
        ?int $sizeW = 256,
        ?int $sizeH = 256,
        int $sourceWidth = 1024,
        int $sourceHeight = 1024
    ): array {
        if ($regionFeature === 'full') {
            $region = [
                'feature' => 'full',
                'x' => 0,
                'y' => 0,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ];
        } else {
            $region = [
                'feature' => $regionFeature,
                'x' => $regionCoords[0],
                'y' => $regionCoords[1],
                'width' => $regionCoords[2],
                'height' => $regionCoords[3],
            ];
        }

        return [
            'source' => [
                'type' => 'original',
                'filepath' => '/var/www/files/original/abc123.jpg',
                'media_type' => 'image/jpeg',
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ],
            'region' => $region,
            'size' => [
                'feature' => $sizeFeature,
                'width' => $sizeW,
                'height' => $sizeH,
            ],
            'mirror' => [
                'feature' => 'default',
            ],
            'rotation' => [
                'feature' => 'noRotation',
                'degrees' => 0,
            ],
            'quality' => [
                'feature' => 'default',
            ],
            'format' => [
                'feature' => 'image/jpeg',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Wrong tile type → null
    // ------------------------------------------------------------------

    public function testReturnsNullForWrongType(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff');
        $tileInfo['tile_type'] = 'deepzoom';

        $transform = $this->makeTransform();
        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNull($result);
    }

    public function testReturnsNullForEmptyTileInfo(): void
    {
        $transform = $this->makeTransform();
        $result = ($this->plugin)([], $transform);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // Tiled TIFF — top-left tile at full resolution
    // ------------------------------------------------------------------

    public function testTiledTiffReturnsResultForTopLeftTile(): void
    {
        // 1024x1024 image, tile 256: top-left tile at full resolution.
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('cell', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('region', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertSame(0, $result['cell']['column']);
        $this->assertSame(0, $result['cell']['row']);
        $this->assertSame('regionByPx', $result['region']['feature']);
        $this->assertSame(0, $result['region']['x']);
        $this->assertSame(0, $result['region']['y']);
        $this->assertStringEndsWith('.tif', $result['source']['filepath']);
    }

    // ------------------------------------------------------------------
    // Tiled TIFF — inner tile (column=1, row=1)
    // ------------------------------------------------------------------

    public function testTiledTiffReturnsResultForInnerTile(): void
    {
        // 1024x1024 image, tile at column=1, row=1 at full resolution.
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [256, 256, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['cell']['column']);
        $this->assertSame(1, $result['cell']['row']);
    }

    // ------------------------------------------------------------------
    // Tiled TIFF — last tile at full resolution
    // ------------------------------------------------------------------

    public function testTiledTiffReturnsResultForLastTile(): void
    {
        // 1024x1024 image: 4×4 grid at full resolution (256 tiles).
        // Last tile at (768, 768, 256, 256).
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [768, 768, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertTrue($result['cell']['isLastCell']);
        $this->assertSame(3, $result['cell']['column']);
        $this->assertSame(3, $result['cell']['row']);
    }

    // ------------------------------------------------------------------
    // Tiled TIFF — non-square last tile (cropped)
    // ------------------------------------------------------------------

    public function testTiledTiffReturnsResultForCroppedLastTile(): void
    {
        // 640x640 image: 3×3 grid at full res, last tile cropped to
        // 128x128. But getLevelAndPosition matches this at scaleFactor=2
        // (2×2 grid) since 640-512=128 fits both levels. The reversed
        // scale factor loop finds scaleFactor=2 first.
        $tileInfo = $this->makeTileInfo('tiled_tiff', 640, 640);
        $transform = $this->makeTransform(
            'regionByPx',
            [512, 512, 128, 128],
            'max',
            128,
            128,
            640,
            640
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertTrue($result['cell']['isLastCell']);
    }

    // ------------------------------------------------------------------
    // Non-aligned region → null
    // ------------------------------------------------------------------

    public function testReturnsNullForNonAlignedRegion(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        // Region starts at x=100 — not aligned on 256 grid.
        $transform = $this->makeTransform(
            'regionByPx',
            [100, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // JPEG 2000 — top-left tile
    // ------------------------------------------------------------------

    public function testJpeg2000ReturnsResultForTile(): void
    {
        $tileInfo = $this->makeTileInfo('jpeg2000', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('.jp2', $result['source']['filepath']);
    }

    // ------------------------------------------------------------------
    // JPEG 2000 — second column tile
    // ------------------------------------------------------------------

    public function testJpeg2000ReturnsResultForSecondColumn(): void
    {
        $tileInfo = $this->makeTileInfo('jpeg2000', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [256, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['cell']['column']);
        $this->assertSame(0, $result['cell']['row']);
    }

    // ------------------------------------------------------------------
    // Media type correctness
    // ------------------------------------------------------------------

    public function testJpeg2000HasCorrectMediaType(): void
    {
        $tileInfo = $this->makeTileInfo('jpeg2000', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame('image/jp2', $result['source']['media_type']);
    }

    public function testTiledTiffHasCorrectMediaType(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame('image/tiff', $result['source']['media_type']);
    }

    // ------------------------------------------------------------------
    // Overlap is always zero
    // ------------------------------------------------------------------

    public function testOverlapIsAlwaysZero(): void
    {
        // Tiled TIFF.
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );
        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['source']['overlap']);

        // JPEG 2000.
        $tileInfo = $this->makeTileInfo('jpeg2000', 1024, 1024);
        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['source']['overlap']);
    }

    // ------------------------------------------------------------------
    // Source dimensions match tileInfo
    // ------------------------------------------------------------------

    public function testSourceDimensionsMatchTileInfo(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 2048, 1536);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            2048,
            1536
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(2048, $result['source']['width']);
        $this->assertSame(1536, $result['source']['height']);
    }

    // ------------------------------------------------------------------
    // Region and size pass through from original transform
    // ------------------------------------------------------------------

    public function testRegionAndSizePassThrough(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        // Half-resolution tile: region 512x512, size 256x256.
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 512, 512],
            'sizeByWh',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        // Region should be passed through unchanged.
        $this->assertSame(
            $transform['region'],
            $result['region']
        );
        // Size should be passed through unchanged.
        $this->assertSame(
            $transform['size'],
            $result['size']
        );
    }

    // ------------------------------------------------------------------
    // Unsupported transform features → null
    // ------------------------------------------------------------------

    public function testReturnsNullForUnsupportedRegionFeature(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform();
        $transform['region']['feature'] = 'unsupported';

        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNull($result);
    }

    public function testReturnsNullForUnsupportedSizeFeature(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform();
        $transform['size']['feature'] = 'unsupported';

        $result = ($this->plugin)($tileInfo, $transform);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // File paths are correctly built
    // ------------------------------------------------------------------

    public function testTiledTiffFilePathsAreCorrectlyBuilt(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(
            '/var/www/files/tile' . DIRECTORY_SEPARATOR . 'abc123.tif',
            $result['source']['filepath']
        );
        $this->assertSame(
            'http://example.org/files/tile/abc123.tif',
            $result['source']['fileurl']
        );
    }

    public function testJpeg2000FilePathsAreCorrectlyBuilt(): void
    {
        $tileInfo = $this->makeTileInfo('jpeg2000', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(
            '/var/www/files/tile' . DIRECTORY_SEPARATOR . 'abc123.jp2',
            $result['source']['filepath']
        );
        $this->assertSame(
            'http://example.org/files/tile/abc123.jp2',
            $result['source']['fileurl']
        );
    }

    // ------------------------------------------------------------------
    // derivativeType is 'tile'
    // ------------------------------------------------------------------

    public function testDerivativeTypeIsTile(): void
    {
        $tileInfo = $this->makeTileInfo('tiled_tiff', 1024, 1024);
        $transform = $this->makeTransform(
            'regionByPx',
            [0, 0, 256, 256],
            'max',
            256,
            256,
            1024,
            1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame('tile', $result['source']['derivativeType']);
    }
}
