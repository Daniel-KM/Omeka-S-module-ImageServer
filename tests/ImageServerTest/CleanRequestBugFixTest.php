<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;
use ImageServer\Controller\ImageController;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;
use Laminas\View\Model\ViewModel;

/**
 * Regression tests for bug fixes in ImageController::cleanRequest().
 *
 * Each test reproduces a specific bug using a real 1000×500 JPEG image
 * and documents the IIIF URL that triggers the bug.
 *
 * The tests call cleanRequest() via reflection so that we verify the
 * parsing/calculation logic without needing the full image transformation
 * pipeline (which sends headers and uses readfile()).
 *
 * @covers \ImageServer\Controller\ImageController::cleanRequest
 */
class CleanRequestBugFixTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    /**
     * Known storage id for the test image (deterministic, not random).
     */
    private const STORAGE_ID = 'imageservertestimg00001';
    private const EXTENSION = 'jpg';
    private const SOURCE_WIDTH = 1000;
    private const SOURCE_HEIGHT = 500;

    private string $testImagePath = '';
    private int $mediaId = 0;

    /**
     * @var ImageController
     */
    private $controller;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        // Create a real 1000×500 JPEG test image.
        $dir = $basePath . '/original';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->testImagePath = $dir . '/'
            . self::STORAGE_ID . '.' . self::EXTENSION;
        $img = imagecreatetruecolor(self::SOURCE_WIDTH, self::SOURCE_HEIGHT);
        $grey = imagecolorallocate($img, 128, 128, 128);
        imagefill($img, 0, 0, $grey);
        imagejpeg($img, $this->testImagePath, 75);
        imagedestroy($img);

        // Create item + media entity pointing to the image.
        $item = $this->createItem();
        $this->mediaId = $this->createMediaWithImage($item->id());

        // Get the controller with all its plugins wired up.
        $controllerManager = $services->get('ControllerManager');
        $this->controller = $controllerManager->get(
            ImageController::class
        );
    }

    public function tearDown(): void
    {
        if ($this->testImagePath && file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
        }
        $this->cleanupResources();
        parent::tearDown();
    }

    // ================================================================
    // Helper: insert media with a known storageId and cached dimensions.
    // ================================================================

    private function createMediaWithImage(int $itemId): int
    {
        $connection = $this->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO `resource`'
            . ' (`owner_id`, `resource_type`, `is_public`,'
            . ' `created`, `modified`)'
            . ' VALUES (1, :type, 1, :now, :now)',
            ['type' => 'Omeka\\Entity\\Media', 'now' => $now]
        );
        $resourceId = (int) $connection->lastInsertId();

        $data = json_encode([
            'dimensions' => [
                'original' => [
                    'width' => self::SOURCE_WIDTH,
                    'height' => self::SOURCE_HEIGHT,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $connection->executeStatement(
            'INSERT INTO `media`'
            . ' (`id`, `item_id`, `ingester`, `renderer`,'
            . ' `media_type`, `storage_id`, `extension`, `position`,'
            . ' `has_original`, `has_thumbnails`,'
            . ' `source`, `sha256`, `size`, `data`)'
            . ' VALUES (:id, :itemId, :ingester, :renderer,'
            . ' :mediaType, :storageId, :extension, 1,'
            . ' 1, 0,'
            . ' :source, :sha256, :fsize, :data)',
            [
                'id' => $resourceId,
                'itemId' => $itemId,
                'ingester' => 'upload',
                'renderer' => 'file',
                'mediaType' => 'image/jpeg',
                'storageId' => self::STORAGE_ID,
                'extension' => self::EXTENSION,
                'source' => 'test-image.jpg',
                'sha256' => hash('sha256', self::STORAGE_ID),
                'fsize' => filesize($this->testImagePath) ?: 0,
                'data' => $data,
            ]
        );

        $this->createdMediaIds[] = $resourceId;
        return $resourceId;
    }

    // ================================================================
    // Helper: call cleanRequest() via reflection.
    // ================================================================

    /**
     * Call the protected cleanRequest() on ImageController.
     *
     * @param string $version IIIF API version ('2' or '3').
     * @param string $region Region parameter (e.g. 'full', '0,0,500,500').
     * @param string $size Size parameter (e.g. '200,', ',100', '!400,300').
     * @return array|null The transform array, or null on error.
     */
    private function callCleanRequest(
        string $version,
        string $region,
        string $size
    ): ?array {
        // Wire the route match so $this->params() works.
        $routeMatch = new RouteMatch([
            'version' => $version,
            'id' => (string) $this->mediaId,
            'region' => $region,
            'size' => $size,
            'rotation' => '0',
            'quality' => 'default',
            'format' => 'jpg',
        ]);
        $event = new MvcEvent();
        $event->setRouteMatch($routeMatch);
        $event->setApplication($this->getApplication());
        $this->controller->setEvent($event);

        // Set requestedApiVersion (normally done by fetchAction).
        $ref = new \ReflectionProperty($this->controller, 'requestedApiVersion');
        $ref->setAccessible(true);
        $ref->setValue($this->controller, $version);

        // Set _view (normally done by fetchAction).
        $viewRef = new \ReflectionProperty($this->controller, '_view');
        $viewRef->setAccessible(true);
        $viewRef->setValue($this->controller, new ViewModel());

        // Get a fresh MediaRepresentation from the database.
        $this->getEntityManager()->clear();
        $media = $this->api()->read('media', $this->mediaId)->getContent();

        // Call cleanRequest via reflection.
        $method = new \ReflectionMethod($this->controller, 'cleanRequest');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $media);
    }

    // ================================================================
    // Bug fix: undefined $destinationWidth/$destinationHeight
    // in the v3 upscale guard.
    //
    // The upscale check used local variables $destinationWidth and
    // $destinationHeight that were only set in the w,h / w, / ,h / !w,h
    // branches. For other branches (pct:, max, full) the variables were
    // undefined (null), so the check `null > region_width` was always
    // false and upscaling was silently allowed.
    //
    // Fix: use $transform['size']['width'] / ['height'] which are
    // always set by every branch.
    // ================================================================

    /**
     * IIIF URL: /iiif/3/{id}/full/pct:150/0/default.jpg
     *
     * pct:150 on 1000×500 produces size 1500×750, which exceeds the
     * region 1000×500. In v3 without ^, this must be rejected.
     */
    public function testUpscaleBlockedForPercentageAbove100InV3(): void
    {
        $result = $this->callCleanRequest('3', 'full', 'pct:150');
        $this->assertNull(
            $result,
            'pct:150 in v3 without ^ must be rejected (upscale).'
            . ' IIIF URL: /iiif/3/{id}/full/pct:150/0/default.jpg'
        );
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/^pct:150/0/default.jpg
     *
     * With ^ prefix, upscaling is allowed.
     */
    public function testUpscaleAllowedWithCaretPct(): void
    {
        $result = $this->callCleanRequest('3', 'full', '^pct:150');
        $this->assertNotNull($result,
            'IIIF URL: /iiif/3/{id}/full/^pct:150/0/default.jpg');
        $this->assertSame(1500, $result['size']['width']);
        $this->assertSame(750, $result['size']['height']);
    }

    /**
     * Verify that pct:50 (downscale) is always allowed in v3.
     */
    public function testDownscaleAlwaysAllowedInV3(): void
    {
        $result = $this->callCleanRequest('3', 'full', 'pct:50');
        $this->assertNotNull($result);
        $this->assertSame(500, $result['size']['width']);
        $this->assertSame(250, $result['size']['height']);
    }

    // ================================================================
    // Bug fix: inverted ratio in sizeByW and sizeByH.
    //
    // The percentage was computed as region/destination instead of
    // destination/region. For sizeByW (w,) the proportional height
    // was region_h × (region_w / dest_w) instead of
    // region_h × (dest_w / region_w). This produced wildly wrong
    // values that were invisible in the final image (because
    // AbstractImager::_prepareExtraction recalculates) but broke the
    // v3 upscale guard.
    // ================================================================

    /**
     * IIIF URL: /iiif/3/{id}/full/200,/0/default.jpg
     *
     * 200, on 1000×500 → width=200, height=100.
     * Old bug: height = 500 × (1000/200) = 2500.
     */
    public function testSizeByWCalculatesCorrectHeight(): void
    {
        $result = $this->callCleanRequest('3', 'full', '200,');
        $this->assertNotNull(
            $result,
            'Size 200, on 1000×500 should succeed.'
            . ' IIIF URL: /iiif/3/{id}/full/200,/0/default.jpg'
        );
        $this->assertSame('sizeByW', $result['size']['feature']);
        $this->assertSame(200, $result['size']['width']);
        $this->assertSame(
            100,
            $result['size']['height'],
            'Height should be 100 (= 500 × 200/1000), not 2500.'
        );
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/,100/0/default.jpg
     *
     * ,100 on 1000×500 → width=200, height=100.
     * Old bug: width = 1000 × (500/100) = 5000.
     */
    public function testSizeByHCalculatesCorrectWidth(): void
    {
        $result = $this->callCleanRequest('3', 'full', ',100');
        $this->assertNotNull(
            $result,
            'Size ,100 on 1000×500 should succeed.'
            . ' IIIF URL: /iiif/3/{id}/full/,100/0/default.jpg'
        );
        $this->assertSame('sizeByH', $result['size']['feature']);
        $this->assertSame(
            200,
            $result['size']['width'],
            'Width should be 200 (= 1000 × 100/500), not 5000.'
        );
        $this->assertSame(100, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/2/{id}/full/200,/0/default.jpg
     *
     * Same test in v2 (no upscale guard) to confirm the ratio values
     * are computed correctly regardless of IIIF version.
     */
    public function testSizeByWCorrectInV2(): void
    {
        $result = $this->callCleanRequest('2', 'full', '200,');
        $this->assertNotNull($result);
        $this->assertSame(200, $result['size']['width']);
        $this->assertSame(100, $result['size']['height']);
    }

    // ================================================================
    // Bug fix: inverted ratio in sizeByConfinedWh (!w,h).
    //
    // Same class of bug as sizeByW/sizeByH: the percentage was
    // computed as region/destination instead of destination/region.
    // Unlike sizeByW/sizeByH, this bug IS visible in the final image
    // because _prepareExtraction uses the width and height as bounding
    // box for sizeByConfinedWh.
    //
    // Example: !400,300 on 1000×500
    //   Old: pctW = 1000/400 = 2.5, pctH = 500/300 = 1.67
    //        → width = 1000×1.67 = 1667, height = 300
    //        → _prepareExtraction with bbox (1667,300) → 600×300 (wrong)
    //   Fix: pctW = 400/1000 = 0.4, pctH = 300/500 = 0.6
    //        → width = 400, height = 500×0.4 = 200
    //        → _prepareExtraction with bbox (400,200) → 400×200 (correct)
    // ================================================================

    /**
     * IIIF URL: /iiif/2/{id}/full/!400,300/0/default.jpg
     *
     * !400,300 on 1000×500 → best fit: 400×200.
     * Old bug: would produce 1667×300 in transform, then 600×300 in
     * the actual image output.
     */
    public function testConfinedWhFitsWithinBoundingBox(): void
    {
        $result = $this->callCleanRequest('2', 'full', '!400,300');
        $this->assertNotNull(
            $result,
            'Size !400,300 on 1000×500 should succeed.'
            . ' IIIF URL: /iiif/2/{id}/full/!400,300/0/default.jpg'
        );
        $this->assertSame('sizeByConfinedWh', $result['size']['feature']);
        $this->assertSame(400, $result['size']['width']);
        $this->assertSame(
            200,
            $result['size']['height'],
            'Best fit in !400,300 for 2:1 ratio should be 400×200,'
            . ' not 1667×300.'
        );
        // Verify both dimensions fit within the bounding box.
        $this->assertLessThanOrEqual(400, $result['size']['width']);
        $this->assertLessThanOrEqual(300, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/2/{id}/full/!600,100/0/default.jpg
     *
     * Height-limited case: pctW=0.6 > pctH=0.2
     * → width = 1000×0.2 = 200, height = 100.
     */
    public function testConfinedWhHeightLimited(): void
    {
        $result = $this->callCleanRequest('2', 'full', '!600,100');
        $this->assertNotNull($result);
        $this->assertSame(200, $result['size']['width']);
        $this->assertSame(100, $result['size']['height']);
        $this->assertLessThanOrEqual(600, $result['size']['width']);
        $this->assertLessThanOrEqual(100, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/!400,300/0/default.jpg
     *
     * Same test in v3: the corrected ratio (400×200) must NOT trigger
     * the upscale guard (both dimensions ≤ region).
     */
    public function testConfinedWhDoesNotTriggerUpscaleInV3(): void
    {
        $result = $this->callCleanRequest('3', 'full', '!400,300');
        $this->assertNotNull(
            $result,
            '!400,300 in v3 should succeed: 400×200 fits within'
            . ' region 1000×500.'
        );
        $this->assertSame(400, $result['size']['width']);
        $this->assertSame(200, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/2/{id}/full/!1000,500/0/default.jpg
     *
     * Bounding box exactly equals region → should be 'max' (no scaling).
     */
    public function testConfinedWhExactMatchIsMax(): void
    {
        $result = $this->callCleanRequest('2', 'full', '!1000,500');
        $this->assertNotNull($result);
        $this->assertSame('max', $result['size']['feature']);
        $this->assertSame(self::SOURCE_WIDTH, $result['size']['width']);
        $this->assertSame(self::SOURCE_HEIGHT, $result['size']['height']);
    }

    // ================================================================
    // Bug fix: sizeByConfinedWh quick check too restrictive when both
    // bounding box dimensions are strictly larger than the region.
    //
    // The quick check in cleanRequest used:
    //   (w >= regionW && h == regionH) || (w == regionW && h >= regionH)
    // This missed the case where BOTH dimensions are strictly larger
    // (e.g. !1200,600 on 1000×500). The code fell through to the
    // ratio calculation, producing an upscaled size (1200×600).
    //
    // In v3 without ^: the upscale guard rejected the request with
    // a 400 error, even though 1000×500 fits within 1200×600.
    // In v2: the image was silently upscaled, violating the IIIF
    // spec ("must not be scaled up beyond the full size").
    //
    // Fix: check (w >= regionW && h >= regionH && !upscale), keeping
    // the existing conditions for the ^ (upscale) case.
    // ================================================================

    /**
     * IIIF URL: /iiif/3/{id}/full/!1200,600/0/default.jpg
     *
     * Bounding box 1200×600 is strictly larger than region 1000×500
     * in both dimensions, with the same 2:1 aspect ratio.
     * Without ^, this must NOT be rejected: 1000×500 fits in the box.
     *
     * Old bug: the quick check missed this case, the ratio calculation
     * produced 1200×600, and the upscale guard rejected it (400).
     */
    public function testConfinedWhBothDimensionsLargerInV3(): void
    {
        $result = $this->callCleanRequest('3', 'full', '!1200,600');
        $this->assertNotNull(
            $result,
            '!1200,600 in v3 without ^ on 1000×500: the image fits'
            . ' within the bounding box, must not be rejected.'
            . ' IIIF URL: /iiif/3/{id}/full/!1200,600/0/default.jpg'
        );
        $this->assertSame('max', $result['size']['feature']);
        $this->assertSame(self::SOURCE_WIDTH, $result['size']['width']);
        $this->assertSame(self::SOURCE_HEIGHT, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/2/{id}/full/!1200,600/0/default.jpg
     *
     * Same test in v2: must return max (1000×500), not upscale
     * to 1200×600.
     */
    public function testConfinedWhBothDimensionsLargerInV2(): void
    {
        $result = $this->callCleanRequest('2', 'full', '!1200,600');
        $this->assertNotNull($result);
        $this->assertSame('max', $result['size']['feature']);
        $this->assertSame(self::SOURCE_WIDTH, $result['size']['width']);
        $this->assertSame(self::SOURCE_HEIGHT, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/^!1200,600/0/default.jpg
     *
     * With ^ prefix, the upscaled best-fit should be 1200×600
     * (same aspect ratio), NOT max.
     */
    public function testConfinedWhBothDimensionsLargerWithUpscale(): void
    {
        $result = $this->callCleanRequest('3', 'full', '^!1200,600');
        $this->assertNotNull($result);
        // With upscaling and same aspect ratio, result is 1200×600.
        $this->assertSame(1200, $result['size']['width']);
        $this->assertSame(600, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/!2000,800/0/default.jpg
     *
     * Bounding box 2000×800 with different aspect ratio (2.5:1 vs 2:1).
     * Without ^: must return max (1000×500).
     */
    public function testConfinedWhBothLargerDifferentRatioInV3(): void
    {
        $result = $this->callCleanRequest('3', 'full', '!2000,800');
        $this->assertNotNull($result);
        $this->assertSame('max', $result['size']['feature']);
        $this->assertSame(self::SOURCE_WIDTH, $result['size']['width']);
        $this->assertSame(self::SOURCE_HEIGHT, $result['size']['height']);
    }

    /**
     * IIIF URL: /iiif/3/{id}/full/^!2000,800/0/default.jpg
     *
     * With ^: upscaled best-fit within 2000×800 for 2:1 ratio.
     * pctW = 2000/1000 = 2.0, pctH = 800/500 = 1.6, min = 1.6
     * → 1600×800.
     */
    public function testConfinedWhBothLargerDifferentRatioWithUpscale(): void
    {
        $result = $this->callCleanRequest('3', 'full', '^!2000,800');
        $this->assertNotNull($result);
        $this->assertSame(1600, $result['size']['width']);
        $this->assertSame(800, $result['size']['height']);
    }
}
