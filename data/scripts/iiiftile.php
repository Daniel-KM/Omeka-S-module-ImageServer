<?php declare(strict_types=1);

// Lightweight IIIF Image API tile server via vips (php-vips or cli).
//
// Bypasses the full Omeka S MVC stack for maximum performance.
// Handles region extraction and resizing via vips CLI with disk caching.
// Subsequent requests for the same tile are served directly from cache.
//
// Requirements: vips cli (libvips-tools) or php-vips (jcupitt/vips).
//
// Setup: add these rules in .htaccess BEFORE the Omeka catch-all, so before
// `RewriteCond %{REQUEST_FILENAME} -f`. The version segment (2/3) is optional.
//
//   # Module ImageServer: fast IIIF tile server.
//   RewriteCond %{REQUEST_URI} /iiif/([23]/)?[^/]+/[^/]+/[^/]+/[^/]+/[^.]+\.\w+$
//   RewriteRule iiif/(.*) modules/ImageServer/data/scripts/iiiftile.php [END,E=IIIF_PATH:/iiif/$1]
//
// If modules are installed via composer, replace "modules/" by "composer-addons/modules/".
//
// @todo Support quality "bitonal" (1-bit monochrome).
// @todo Distinguish ^w, (upscale allowed, IIIF v3) from w, (no upscale). Currently both are treated identically.
//
// @link https://iiif.io/api/image/2.1/
// @link https://iiif.io/api/image/3.0/

// Detect vips: prefer php-vips library, then cli, else fall back to Omeka S
// standard stack.
// php-vips v1 (ext-vips) or v2 (ext-ffi) are loaded via Composer autoloader
// from the Omeka vendor directory.

$usePhpVips = false;
$vips = '';
$autoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
    if (class_exists('Jcupitt\Vips\Image')) {
        $usePhpVips = true;
    }
}
if (!$usePhpVips) {
    $vips = trim(shell_exec('which vips 2>/dev/null') ?? '');
    if (!$vips) {
        require dirname(__DIR__, 4) . '/index.php';
        return;
    }
}

// Resolve Omeka root from script location.
define('OMEKA_PATH', dirname(__DIR__, 4));

define('MEDIA_TYPES', [
    'gif' => 'image/gif',
    'jp2' => 'image/jp2',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'tif' => 'image/tiff',
    'webp' => 'image/webp',
]);

// ---------------------------------------------------------------------------
// 1. Parse IIIF URL
// ---------------------------------------------------------------------------

$iiifPath = $_SERVER['REDIRECT_IIIF_PATH']
    ?? $_SERVER['IIIF_PATH']
    ?? null;

if (!$iiifPath) {
    // Fallback: extract from REQUEST_URI.
    $uri = strtok($_SERVER['REQUEST_URI'], '?');
    if (preg_match('#(iiif/[23]/.+)$#', $uri, $m)) {
        $iiifPath = '/' . $m[1];
    }
}

// Pattern: /iiif/{version?}/{identifier}/{region}/{size}/{rotation}/{quality}.{format}
// Version is optional: it may be omitted when IiifServer is configured without.
if (!$iiifPath
    || !preg_match(
        '#^/iiif/(?:([23])/)?([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^.]+)\.(\w+)$#',
        $iiifPath,
        $matches
    )
) {
    // Not a tile request — let Omeka handle it.
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid IIIF Image API request.';
    exit;
}

[, $version, $identifier, $region, $size, $rotation, $quality, $format] = $matches;
$version = $version ?: '3';
$identifier = rawurldecode($identifier);

// Only handle standard tile requests. Anything exotic goes to Omeka.
$supportedFormats = [
    'gif',
    'jp2',
    'jpg',
    'jpeg',
    'png',
    'tif',
    'tiff',
    'webp',
];
if (!in_array($format, $supportedFormats, true)) {
    http_response_code(501);
    header('Content-Type: text/plain');
    echo 'Unsupported format.';
    exit;
}
$formatAliases = [
    'jpeg' => 'jpg',
    'tiff' => 'tif',
];
$outputFormat = $formatAliases[$format] ?? $format;

// ---------------------------------------------------------------------------
// 2. Resolve identifier to file path and check access
// ---------------------------------------------------------------------------

$filesPath = OMEKA_PATH . '/files';

$ini = parse_ini_file(OMEKA_PATH . '/config/database.ini');
if (!$ini) {
    http_response_code(500);
    exit('Cannot read database config.');
}
try {
    $dsn = 'mysql:host=' . ($ini['host'] ?? 'localhost')
        . (!empty($ini['port']) ? ';port=' . $ini['port'] : '')
        . ';dbname=' . $ini['dbname'];
    $pdo = new PDO($dsn, $ini['user'], $ini['password'], [
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

// Lookup by numeric media ID or by storage filename.
if (ctype_digit($identifier)) {
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT m.storage_id, m.extension, r.is_public, m.media_type
        FROM media m JOIN resource r ON m.id = r.id
        WHERE m.id = ? LIMIT 1
        SQL
    );
    $stmt->execute([(int) $identifier]);
} else {
    // Filename identifier: strip extension to get the storage_id, or match with
    // extension.
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT m.storage_id, m.extension, r.is_public, m.media_type
        FROM media m JOIN resource r ON m.id = r.id
        WHERE CONCAT(m.storage_id, '.', m.extension) = ?
           OR m.storage_id = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$identifier, $identifier]);
}
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$pdo = null;

if (!$row) {
    http_response_code(404);
    exit('Media not found.');
}
if (!$row['is_public']) {
    http_response_code(403);
    exit('Forbidden.');
}
if (strpos((string) $row['media_type'], 'image/') !== 0) {
    http_response_code(400);
    exit('Not an image.');
}

$ext = $row['extension'] ? '.' . $row['extension'] : '';
$filepath = $filesPath . '/original/' . $row['storage_id'] . $ext;

if (!file_exists($filepath)) {
    http_response_code(404);
    exit('Image file not found.');
}

// ---------------------------------------------------------------------------
// 3. Check disk cache
// ---------------------------------------------------------------------------

$cacheDir = $filesPath . '/tile/cache/' . $identifier;
$cacheFile = $cacheDir
    . '/' . $region
    . '/' . $size
    . '/' . $rotation
    . '/' . $quality
    . '.' . $outputFormat;

if (file_exists($cacheFile)) {
    header('Content-Type: ' . (MEDIA_TYPES[$outputFormat] ?? 'image/jpeg'));
    header('Content-Length: ' . filesize($cacheFile));
    header('Cache-Control: public, max-age=86400, s-maxage=604800');
    header('Access-Control-Allow-Origin: *');
    header('X-IIIF-Cache: hit');
    readfile($cacheFile);
    exit;
}

// ---------------------------------------------------------------------------
// 4. Parse IIIF parameters
// ---------------------------------------------------------------------------

// Get source dimensions (fast: read only header).
$imgInfo = @getimagesize($filepath);
if (!$imgInfo) {
    http_response_code(500);
    exit('Cannot read image dimensions.');
}
$srcWidth = $imgInfo[0];
$srcHeight = $imgInfo[1];

// EXIF orientation: swap dimensions for 90°/270° rotations.
try {
    $exif = @exif_read_data($filepath);
} catch (\Throwable $e) {
    $exif = false;
}
if ($exif && !empty($exif['Orientation']) && $exif['Orientation'] >= 5) {
    [$srcWidth, $srcHeight] = [$srcHeight, $srcWidth];
}

// --- Region ---
if ($region === 'full') {
    $rX = 0;
    $rY = 0;
    $rW = $srcWidth;
    $rH = $srcHeight;
} elseif ($region === 'square') {
    $min = min($srcWidth, $srcHeight);
    $rX = (int) (($srcWidth - $min) / 2);
    $rY = (int) (($srcHeight - $min) / 2);
    $rW = $rH = $min;
} elseif (strpos($region, 'pct:') === 0) {
    $pct = explode(',', substr($region, 4));
    $rX = (int) round($srcWidth * $pct[0] / 100);
    $rY = (int) round($srcHeight * $pct[1] / 100);
    $rW = (int) round($srcWidth * $pct[2] / 100);
    $rH = (int) round($srcHeight * $pct[3] / 100);
} else {
    $parts = explode(',', $region);
    if (count($parts) !== 4) {
        http_response_code(400);
        exit('Invalid region.');
    }
    [$rX, $rY, $rW, $rH] = array_map('intval', $parts);
}

// Clamp to image bounds.
$rW = min($rW, $srcWidth - $rX);
$rH = min($rH, $srcHeight - $rY);
if ($rW <= 0 || $rH <= 0) {
    http_response_code(400);
    exit('Invalid region dimensions.');
}

// --- Size ---
if ($size === 'max' || $size === 'full' || $size === '^max') {
    $dW = $rW;
    $dH = $rH;
} elseif (strpos($size, 'pct:') === 0) {
    $pct = (float) substr($size, 4);
    $dW = (int) round($rW * $pct / 100);
    $dH = (int) round($rH * $pct / 100);
} elseif (strpos($size, '!') === 0) {
    // Best fit within w,h.
    $parts = explode(',', substr($size, 1));
    $maxW = (int) $parts[0];
    $maxH = (int) ($parts[1] ?? 0);
    $scale = min($maxW / $rW, $maxH / $rH);
    $dW = (int) round($rW * $scale);
    $dH = (int) round($rH * $scale);
} else {
    $parts = explode(',', $size);
    $dW = $parts[0] !== '' ? (int) $parts[0] : 0;
    $dH = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : 0;
    if ($dW && !$dH) {
        $dH = (int) round($rH * $dW / $rW);
    } elseif (!$dW && $dH) {
        $dW = (int) round($rW * $dH / $rH);
    }
}

if ($dW <= 0 || $dH <= 0) {
    http_response_code(400);
    exit('Invalid size.');
}

// --- Rotation ---
$mirror = false;
$rot = $rotation;
if (strpos($rot, '!') === 0) {
    $mirror = true;
    $rot = substr($rot, 1);
}
$rotDegrees = (float) $rot;

// --- Quality ---
// 'default', 'color', 'gray', 'bitonal' — handled via vips.

// ---------------------------------------------------------------------------
// 5. Check for Omeka derivative shortcut
// ---------------------------------------------------------------------------

// If the request is for the full region at a standard Omeka size, redirect to
// the pre-existing derivative (no processing needed).
if ($region === 'full' && $rotDegrees == 0 && !$mirror
    && in_array($quality, ['default', 'color'], true)
    && $outputFormat === 'jpg'
) {
    $derivatives = [
        'medium' => 200,
        'large' => 800,
    ];
    foreach ($derivatives as $type => $maxDim) {
        if (($dW === $maxDim || $dH === $maxDim)
            && abs($dW / $dH - $srcWidth / $srcHeight) < 0.02
        ) {
            $thumbPath = $filesPath
                . '/' . $type . '/'
                . (isset($row) ? $row['storage_id'] . '.jpg' : preg_replace('/\.\w+$/', '.jpg', $identifier));
            if (file_exists($thumbPath)) {
                header('Cache-Control: public, max-age=86400, s-maxage=604800');
                header('Access-Control-Allow-Origin: *');
                header('Content-Type: image/jpeg');
                header('X-IIIF-Cache: derivative');
                readfile($thumbPath);
                exit;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 6. Process with vips
// ---------------------------------------------------------------------------

$cacheFileDir = dirname($cacheFile);
if (!is_dir($cacheFileDir)) {
    @mkdir($cacheFileDir, 0775, true);
}

$needsCrop = $rX !== 0 || $rY !== 0 || $rW !== $srcWidth || $rH !== $srcHeight;
$needsResize = $dW !== $rW || $dH !== $rH;

if ($usePhpVips) {
    // --- php-vips: single process, no temp files, no shell ---
    try {
        $image = \Jcupitt\Vips\Image::newFromFile($filepath, ['access' => 'sequential']);
        $image = $image->autorot();

        if ($needsCrop) {
            $image = $image->extract_area($rX, $rY, $rW, $rH);
        }

        if ($needsResize) {
            $hScale = $dW / ($needsCrop ? $rW : $srcWidth);
            $vScale = $dH / ($needsCrop ? $rH : $srcHeight);
            $image = $image->resize($hScale, ['vscale' => $vScale]);
        }

        if ($mirror) {
            $image = $image->flip('horizontal');
        }

        if ($rotDegrees != 0) {
            if (in_array((int) $rotDegrees, [90, 180, 270], true)) {
                $image = $image->rot('d' . (int) $rotDegrees);
            } else {
                $image = $image->similarity(['angle' => $rotDegrees]);
            }
        }

        if ($quality === 'gray' || $quality === 'grey') {
            $image = $image->colourspace('b-w');
        }

        $saveOptions = [
            'gif' => [],
            'jp2' => [],
            'jpg' => ['Q' => 85, 'strip' => true],
            'png' => ['compression' => 6, 'strip' => true],
            'tif' => ['compression' => 'jpeg', 'Q' => 85, 'strip' => true],
            'webp' => ['Q' => 85, 'strip' => true],
        ];
        $image->writeToFile($cacheFile, $saveOptions[$outputFormat] ?? []);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Image processing failed: ' . $e->getMessage();
        exit;
    }
} else {
    // --- vips CLI: chain of commands with intermediate .v files ---
    $formatOptions = [
        'gif' => '',
        'jp2' => '',
        'jpg' => '[Q=85,strip]',
        'png' => '[compression=6,strip]',
        'tif' => '[compression=jpeg,Q=85,strip]',
        'webp' => '[Q=85,strip]',
    ];
    $outOpt = $formatOptions[$outputFormat] ?? '[Q=85,strip]';

    $tmpDir = sys_get_temp_dir();
    $tmpBase = $tmpDir . '/iiif_' . md5($identifier . microtime(true) . getmypid());

    $commands = [];
    $currentInput = escapeshellarg($filepath);
    $step = 0;
    $tempFiles = [];

    // Auto-orient (EXIF rotation).
    $stepFile = $tmpBase . '_' . (++$step) . '.v';
    $commands[] = sprintf(
        '%s autorot %s %s',
        escapeshellarg($vips),
        $currentInput,
        escapeshellarg($stepFile)
    );
    $currentInput = escapeshellarg($stepFile);
    $tempFiles[] = $stepFile;

    if ($needsCrop) {
        $stepFile = $tmpBase . '_' . (++$step) . '.v';
        $commands[] = sprintf(
            '%s extract_area %s %s %d %d %d %d',
            escapeshellarg($vips),
            $currentInput,
            escapeshellarg($stepFile),
            $rX, $rY, $rW, $rH
        );
        $currentInput = escapeshellarg($stepFile);
        $tempFiles[] = $stepFile;
    }

    if ($needsResize) {
        $stepFile = $tmpBase . '_' . (++$step) . '.v';
        $commands[] = sprintf(
            '%s thumbnail %s %s %d --height %d --size force',
            escapeshellarg($vips),
            $currentInput,
            escapeshellarg($stepFile),
            $dW, $dH
        );
        $currentInput = escapeshellarg($stepFile);
        $tempFiles[] = $stepFile;
    }

    if ($mirror) {
        $stepFile = $tmpBase . '_' . (++$step) . '.v';
        $commands[] = sprintf(
            '%s flip %s %s horizontal',
            escapeshellarg($vips),
            $currentInput,
            escapeshellarg($stepFile)
        );
        $currentInput = escapeshellarg($stepFile);
        $tempFiles[] = $stepFile;
    }

    if ($rotDegrees != 0) {
        $stepFile = $tmpBase . '_' . (++$step) . '.v';
        if (in_array((int) $rotDegrees, [90, 180, 270], true)) {
            $commands[] = sprintf(
                '%s rot %s %s %s',
                escapeshellarg($vips),
                $currentInput,
                escapeshellarg($stepFile),
                'd' . (int) $rotDegrees
            );
        } else {
            $commands[] = sprintf(
                '%s similarity %s %s --angle %s',
                escapeshellarg($vips),
                $currentInput,
                escapeshellarg($stepFile),
                escapeshellarg((string) $rotDegrees)
            );
        }
        $currentInput = escapeshellarg($stepFile);
        $tempFiles[] = $stepFile;
    }

    if ($quality === 'gray' || $quality === 'grey') {
        $stepFile = $tmpBase . '_' . (++$step) . '.v';
        $commands[] = sprintf(
            '%s colourspace %s %s b-w',
            escapeshellarg($vips),
            $currentInput,
            escapeshellarg($stepFile)
        );
        $currentInput = escapeshellarg($stepFile);
        $tempFiles[] = $stepFile;
    }

    $commands[] = sprintf(
        '%s copy %s %s',
        escapeshellarg($vips),
        $currentInput,
        escapeshellarg($cacheFile . $outOpt)
    );

    $fullCmd = implode(' && ', $commands);
    exec($fullCmd . ' 2>&1', $output, $returnCode);

    // Cleanup temp files.
    foreach ($tempFiles as $f) {
        @unlink($f);
    }

    if ($returnCode !== 0 || !file_exists($cacheFile)) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Image processing failed: ' . implode("\n", $output);
        exit;
    }
}

// ---------------------------------------------------------------------------
// 7. Serve the result
// ---------------------------------------------------------------------------

header('Content-Type: ' . (MEDIA_TYPES[$outputFormat] ?? 'image/jpeg'));
header('Content-Length: ' . filesize($cacheFile));
header('Cache-Control: public, max-age=86400, s-maxage=604800');
header('Access-Control-Allow-Origin: *');
header('X-IIIF-Cache: miss');
readfile($cacheFile);
exit;
