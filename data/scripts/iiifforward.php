<?php declare(strict_types=1);

// IIIF Image API forwarder to an external server (Cantaloupe).
//
// Case C: serve a clean or "ark" public identifier while keeping the internal
// storage filename hidden. This script resolves the public identifier to the
// Omeka storage filename, forwards the IIIF Image API request to an internal
// Cantaloupe with that filename, and streams the response back, caching it on
// disk so a subsequent identical request skips the round trip. Cantaloupe never
// sees the public identifier and stays filename-based.
//
// Bypasses the full Omeka S MVC stack for performance (lean resolution +
// stream). Images only: audio and video are served by Iiif Server.
//
// Setup: add this rule in .htaccess BEFORE the Omeka catch-all, so before
// `RewriteCond %{REQUEST_FILENAME} -f`. The version segment (2/3) is optional.
//
//   # Module ImageServer: IIIF forwarder to an external Cantaloupe. RewriteCond
//   %{REQUEST_URI} /iiif/([23]/)?[^/]+/[^/]+/[^/]+/[^/]+/[^.]+\.\w+$
//   RewriteRule iiif/(.*) modules/ImageServer/data/scripts/iiifforward.php
//   [END,E=IIIF_PATH:/iiif/$1]
//
// If modules are installed via composer, replace "modules/" by
// "composer-addons/modules/".
//
// Configuration via environment (vhost or SetEnv):
//   CANTALOUPE_INTERNAL_URL  base url of the internal Cantaloupe, required,
//                            e.g. http://cantaloupe:8182
//   OMEKA_ARK_PROPERTY_ID    property id holding the ark, when the public
//                            identifier is an ark stored in a property,
//                            optional (complex CleanUrl patterns are out of
//                            scope here: use the resolver endpoint of case B
//                            instead).
//
// @link https://iiif.io/api/image/2.1/
// @link https://iiif.io/api/image/3.0/

const MEDIA_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'tif' => 'image/tiff',
    'tiff' => 'image/tiff',
    'jp2' => 'image/jp2',
];

// ---------------------------------------------------------------------------
// 1. Parse the IIIF path
// ---------------------------------------------------------------------------

$iiifPath = $_SERVER['REDIRECT_IIIF_PATH'] ?? $_SERVER['IIIF_PATH'] ?? null;
if (!$iiifPath) {
    $uri = strtok((string) $_SERVER['REQUEST_URI'], '?');
    if (preg_match('#(/iiif/[23]/.+)$#', $uri, $m)) {
        $iiifPath = $m[1];
    }
}
if (!$iiifPath
    || !preg_match(
        '#^/iiif/(?:([23])/)?([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^.]+)\.(\w+)$#',
        $iiifPath,
        $matches
    )
) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid IIIF Image API request.');
}

[, $version, $identifier, $region, $size, $rotation, $quality, $format] = $matches;
$version = $version ?: '3';
$identifier = rawurldecode($identifier);

if (!isset(MEDIA_TYPES[$format])) {
    http_response_code(501);
    header('Content-Type: text/plain');
    exit('Unsupported format.');
}

$cantaloupe = rtrim((string) getenv('CANTALOUPE_INTERNAL_URL'), '/');
if ($cantaloupe === '') {
    http_response_code(500);
    exit('Cantaloupe url not configured.');
}

define('OMEKA_PATH', dirname(__DIR__, 4));
$filesPath = OMEKA_PATH . '/files';

// ---------------------------------------------------------------------------
// 2. Resolve identifier to storage filename and check access (images only)
// ---------------------------------------------------------------------------

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

$select = 'SELECT m.storage_id, m.extension, r.is_public, m.media_type';
$row = false;
if (ctype_digit($identifier)) {
    $stmt = $pdo->prepare("$select FROM media m JOIN resource r ON m.id = r.id WHERE m.id = ? LIMIT 1");
    $stmt->execute([(int) $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$row) {
    $stmt = $pdo->prepare("$select FROM media m JOIN resource r ON m.id = r.id WHERE CONCAT(m.storage_id, '.', m.extension) = ? OR m.storage_id = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
$arkPropertyId = (int) getenv('OMEKA_ARK_PROPERTY_ID');
if (!$row && $arkPropertyId) {
    $stmt = $pdo->prepare("$select FROM value v JOIN media m ON m.id = v.resource_id JOIN resource r ON r.id = m.id WHERE v.property_id = ? AND v.value = ? LIMIT 1");
    $stmt->execute([$arkPropertyId, $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
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

$storageFilename = $row['storage_id'] . ($row['extension'] ? '.' . $row['extension'] : '');

// ---------------------------------------------------------------------------
// 3. Serve from cache if present (keyed by the public identifier)
// ---------------------------------------------------------------------------

$cacheFile = $filesPath . '/tile/cache/' . rawurlencode($identifier)
    . '/' . $version
    . '/' . $region
    . '/' . $size
    . '/' . $rotation
    . '/' . $quality . '.' . $format;

if (is_file($cacheFile)) {
    header('Content-Type: ' . MEDIA_TYPES[$format]);
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

// ---------------------------------------------------------------------------
// 4. Forward to the internal Cantaloupe, then stream and cache
// ---------------------------------------------------------------------------

$target = $cantaloupe . '/iiif/' . $version . '/' . rawurlencode($storageFilename)
    . '/' . $region . '/' . $size . '/' . $rotation . '/' . $quality . '.' . $format;

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 20,
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $status === 0) {
    http_response_code(502);
    exit('Image server unreachable.');
}
if ($status !== 200) {
    http_response_code($status);
    if ($contentType !== '') {
        header('Content-Type: ' . $contentType);
    }
    echo $body;
    exit;
}

// Best-effort disk cache: write atomically so a concurrent request never reads
// a half-written file. Failures are ignored, it is only a cache.
if (!is_dir(dirname($cacheFile))) {
    @mkdir(dirname($cacheFile), 0775, true);
}
$tmp = $cacheFile . '.tmp' . getmypid();
if (@file_put_contents($tmp, $body) !== false) {
    @rename($tmp, $cacheFile);
} else {
    @unlink($tmp);
}

header('Content-Type: ' . ($contentType !== '' ? $contentType : MEDIA_TYPES[$format]));
header('Content-Length: ' . strlen($body));
echo $body;
