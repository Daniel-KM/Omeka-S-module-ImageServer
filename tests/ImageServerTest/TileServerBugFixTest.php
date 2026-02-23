<?php declare(strict_types=1);

namespace ImageServerTest;

use ImageServer\Mvc\Controller\Plugin\TileServerNativeTiled;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for bug fixes in TileServer::getLevelAndPosition().
 *
 * Each test reproduces a specific bug using simulated tileInfo and
 * transform arrays. The tests call TileServerNativeTiled (which
 * inherits getLevelAndPosition from TileServer) directly without
 * needing a controller or real image files.
 *
 * @covers \ImageServer\Mvc\Controller\Plugin\TileServer::getLevelAndPosition
 */
class TileServerBugFixTest extends TestCase
{
    /**
     * @var TileServerNativeTiled
     */
    protected $plugin;

    protected function setUp(): void
    {
        $this->plugin = new TileServerNativeTiled();
    }

    /**
     * Build a tileInfo array.
     */
    private function makeTileInfo(
        int $sourceWidth,
        int $sourceHeight,
        int $tileSize = 256
    ): array {
        return [
            'tile_type' => 'tiled_tiff',
            'metadata_path' => null,
            'media_path' => 'test.tif',
            'url_base' => 'http://example.org/files/tile',
            'path_base' => '/var/www/files/tile',
            'url_query' => '',
            'size' => $tileSize,
            'overlap' => 0,
            'total' => null,
            'source' => [
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ],
            'format' => 'tif',
        ];
    }

    /**
     * Build a transform array.
     */
    private function makeTransform(
        string $regionFeature,
        array $regionCoords,
        string $sizeFeature,
        ?int $sizeW,
        ?int $sizeH,
        int $sourceWidth,
        int $sourceHeight
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
                'filepath' => '/var/www/files/original/test.jpg',
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
            'mirror' => ['feature' => 'default'],
            'rotation' => ['feature' => 'noRotation', 'degrees' => 0],
            'quality' => ['feature' => 'default'],
            'format' => ['feature' => 'image/jpeg'],
        ];
    }

    // ================================================================
    // Bug fix: malformed case expressions in TileServer switch.
    //
    // The switch in getLevelAndPosition (isSingleCell path) had:
    //   case !empty($size['width'] && !empty($size['height'])):
    // which evaluates to `case true:` (or `case false:`) due to
    // PHP operator precedence. `case true:` matches any truthy string,
    // acting as a catch-all that made subsequent cases unreachable.
    //
    // Fix: replace with explicit `case 'sizeByForcedWh':`.
    // ================================================================

    /**
     * sizeByForcedWh on a single-cell image must be recognized.
     *
     * Image: 256×256, tile size 256 → single cell.
     * Size: sizeByForcedWh, 200×150 → both ≤ 256, should be accepted.
     *
     * With the old malformed `case true:`, this feature string would
     * match the catch-all and execute sizeByWh/sizeByConfinedWh logic
     * instead of the sizeByForcedWh check. The result could differ.
     */
    public function testSizeByForcedWhRecognizedOnSingleCell(): void
    {
        $tileInfo = $this->makeTileInfo(256, 256, 256);
        $transform = $this->makeTransform(
            'full', [0, 0, 256, 256],
            'sizeByForcedWh', 200, 150,
            256, 256
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByForcedWh (200×150) on a single 256×256 cell should'
            . ' be accepted, not rejected by the wrong case branch.'
        );
    }

    /**
     * sizeByForcedWh on a multi-cell image must be recognized.
     *
     * Image: 1024×1024, tile size 256, first tile (0,0,256,256).
     * Size: sizeByForcedWh, 256×256 → matches tile exactly.
     *
     * With the old malformed `case true:` in the non-single-cell switch,
     * sizeByForcedWh would match the catch-all at an unpredictable
     * position. The fix added an explicit case merged with sizeByWh.
     */
    public function testSizeByForcedWhRecognizedOnMultiCell(): void
    {
        $tileInfo = $this->makeTileInfo(1024, 1024, 256);
        $transform = $this->makeTransform(
            'regionByPx', [0, 0, 256, 256],
            'sizeByForcedWh', 256, 256,
            1024, 1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByForcedWh on a multi-cell image should be handled'
            . ' by the explicit case, not rejected.'
        );
        $this->assertSame(0, $result['cell']['column']);
        $this->assertSame(0, $result['cell']['row']);
    }

    /**
     * Ensure sizeByW still works (it would have been caught by the
     * old malformed `case true:` as well, masking its own case).
     */
    public function testSizeByWNotCaughtByCatchAll(): void
    {
        // Single cell: 512×384 image, tile size 512.
        $tileInfo = $this->makeTileInfo(512, 384, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 384],
            'sizeByW', 200, null,
            512, 384
        );

        $result = ($this->plugin)($tileInfo, $transform);

        // sizeByW width=200 on a 512px tile: 200 ≤ 512,
        // proportional height = 384×200/512 = 150 ≤ 512.
        $this->assertNotNull(
            $result,
            'sizeByW (200) on single-cell 512×384 should be accepted.'
        );
    }

    // ================================================================
    // Bug fix: multiplication instead of division in tile
    // size check (proportional height calculation).
    //
    // In getLevelAndPosition (isSingleCell path), the checks for
    // sizeByWh/sizeByWhListed/sizeByConfinedWh and sizeByW used:
    //   $source['height'] * $size['width'] * $source['width']
    // instead of:
    //   $source['height'] * $size['width'] / $source['width']
    //
    // This produced astronomically large values that always exceeded
    // cellSize, causing getLevelAndPosition to return null even for
    // valid small-size requests. The image would then fall through
    // to the dynamic processing pipeline instead of using the
    // pre-tiled file.
    //
    // Note: the fourth condition (line 212) using `/ $source['height']`
    // was correct, confirming the `*` on line 210 was a typo.
    // ================================================================

    /**
     * sizeByWh on a single-cell non-square image must not be rejected.
     *
     * Image: 512×384, tile size 512 → single cell.
     * Size: sizeByWh, 200×200.
     *
     * Proportional height check (line 210):
     *   Old: 384 × 200 × 512 = 39,321,600 > 512 → null (wrong).
     *   Fix: 384 × 200 / 512 = 150 ≤ 512 → accepted (correct).
     */
    public function testProportionalHeightNotRejectedForSizeByWh(): void
    {
        $tileInfo = $this->makeTileInfo(512, 384, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 384],
            'sizeByWh', 200, 200,
            512, 384
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByWh (200×200) on a single-cell 512×384 image: the'
            . ' proportional height 384×200/512=150 fits in the tile.'
            . ' Old bug: 384×200×512=39M always exceeded tile size.'
        );
    }

    /**
     * sizeByW on a single-cell non-square image must not be rejected.
     *
     * Image: 512×384, tile size 512 → single cell.
     * Size: sizeByW, width=200.
     *
     * Proportional height check (line 229):
     *   Old: 384 × 200 × 512 = 39,321,600 > 512 → null (wrong).
     *   Fix: 384 × 200 / 512 = 150 ≤ 512 → accepted (correct).
     */
    public function testProportionalHeightNotRejectedForSizeByW(): void
    {
        $tileInfo = $this->makeTileInfo(512, 384, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 384],
            'sizeByW', 200, null,
            512, 384
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByW (200) on a single-cell 512×384 image: the'
            . ' proportional height 384×200/512=150 fits in the tile.'
            . ' Old bug: multiplication instead of division.'
        );
    }

    /**
     * sizeByConfinedWh on a single-cell image must not be rejected.
     *
     * Image: 512×384, tile size 512 → single cell.
     * Size: sizeByConfinedWh, 200×200.
     *
     * Same multiplication bug on line 210 (shared case block with
     * sizeByWh and sizeByWhListed).
     */
    public function testProportionalHeightNotRejectedForConfinedWh(): void
    {
        $tileInfo = $this->makeTileInfo(512, 384, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 384],
            'sizeByConfinedWh', 200, 200,
            512, 384
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByConfinedWh (200×200) on a single-cell 512×384 image'
            . ' should be accepted (proportional height = 150 ≤ 512).'
        );
    }

    /**
     * Requests legitimately too large for the tile must still be
     * rejected: confirm we did not disable the check entirely.
     *
     * Image: 512×384, tile size 512 → single cell.
     * Size: sizeByW, width=600 → 600 > 512 → should return null.
     */
    public function testLegitimatelyOversizedRequestStillRejected(): void
    {
        $tileInfo = $this->makeTileInfo(512, 384, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 384],
            'sizeByW', 600, null,
            512, 384
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNull(
            $result,
            'sizeByW (600) exceeds tile size 512 → must be rejected.'
        );
    }

    /**
     * Edge case: proportional height exactly equals tile size.
     *
     * Image: 512×512, tile size 512 → single cell (square).
     * Size: sizeByW, width=512 → proportional height = 512×512/512 = 512.
     * This is NOT larger than cellSize, so should be accepted.
     */
    public function testProportionalHeightExactlyEqualsTileSize(): void
    {
        $tileInfo = $this->makeTileInfo(512, 512, 512);
        $transform = $this->makeTransform(
            'full', [0, 0, 512, 512],
            'sizeByW', 512, null,
            512, 512
        );

        $result = ($this->plugin)($tileInfo, $transform);

        // 512 > 512 is false, so it should be accepted.
        $this->assertNotNull($result);
    }

    // ================================================================
    // Bug fix: sizeByConfinedWh and sizeByPct were unhandled in the
    // multi-cell branch of getLevelAndPosition().
    //
    // The switch had empty TODO cases for these features, so $count,
    // $cellX, $cellY were never computed. They defaulted to 0, which
    // returned the wrong tile for any cell that is not (0,0).
    //
    // Fix: merge sizeByConfinedWh and sizeByPct into the sizeByWh
    // case group, since cell position depends only on the region,
    // not on how the tile is scaled.
    // ================================================================

    /**
     * sizeByConfinedWh on a multi-cell image, first tile (0,0).
     *
     * Image: 1024×1024, tile 256, region (0,0,256,256).
     * Size: sizeByConfinedWh, 200×200 (fit within 200×200 box).
     */
    public function testConfinedWhMultiCellFirstTile(): void
    {
        $tileInfo = $this->makeTileInfo(1024, 1024, 256);
        $transform = $this->makeTransform(
            'regionByPx', [0, 0, 256, 256],
            'sizeByConfinedWh', 200, 200,
            1024, 1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull(
            $result,
            'sizeByConfinedWh on multi-cell first tile should be accepted.'
        );
        $this->assertSame(0, $result['cell']['column']);
        $this->assertSame(0, $result['cell']['row']);
    }

    /**
     * sizeByConfinedWh on a multi-cell image, tile (1,0).
     *
     * Image: 1024×1024, tile 256, region (256,0,256,256).
     * Before fix: $cellX defaulted to 0 → returned tile (0,0).
     */
    public function testConfinedWhMultiCellSecondColumn(): void
    {
        $tileInfo = $this->makeTileInfo(1024, 1024, 256);
        $transform = $this->makeTransform(
            'regionByPx', [256, 0, 256, 256],
            'sizeByConfinedWh', 200, 200,
            1024, 1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(
            1,
            $result['cell']['column'],
            'Cell at x=256 should be column 1, not 0.'
        );
        $this->assertSame(0, $result['cell']['row']);
    }

    /**
     * sizeByConfinedWh on a multi-cell image, tile (2,1).
     *
     * Image: 1024×1024, tile 256, region (512,256,256,256).
     */
    public function testConfinedWhMultiCellInteriorTile(): void
    {
        $tileInfo = $this->makeTileInfo(1024, 1024, 256);
        $transform = $this->makeTransform(
            'regionByPx', [512, 256, 256, 256],
            'sizeByConfinedWh', 200, 200,
            1024, 1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(2, $result['cell']['column']);
        $this->assertSame(1, $result['cell']['row']);
    }

    /**
     * sizeByPct on a multi-cell image, tile (1,0).
     *
     * Image: 1024×1024, tile 256, region (256,0,256,256).
     * Size: sizeByPct with width=128, height=128 (50% of 256).
     *
     * In practice, cleanRequest converts pct:N to sizeByWh, so
     * sizeByPct never reaches the tile server. This test verifies
     * robustness for direct callers.
     */
    public function testSizeByPctMultiCellSecondColumn(): void
    {
        $tileInfo = $this->makeTileInfo(1024, 1024, 256);
        $transform = $this->makeTransform(
            'regionByPx', [256, 0, 256, 256],
            'sizeByPct', 128, 128,
            1024, 1024
        );

        $result = ($this->plugin)($tileInfo, $transform);

        $this->assertNotNull($result);
        $this->assertSame(
            1,
            $result['cell']['column'],
            'sizeByPct at x=256 should be column 1.'
        );
        $this->assertSame(0, $result['cell']['row']);
    }
}
