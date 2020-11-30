<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Stdlib\Message;

class Tiler extends AbstractPlugin
{
    /**
     * @var array
     */
    protected $params;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param array $params
     * @param LoggerInterface $logger
     */
    public function __construct(array $params, LoggerInterface $logger)
    {
        $this->params = $params;
        $this->logger = $logger;
    }

    /**
     * Tile a media.
     *
     * @var MediaRepresentation $media
     * @var string|bool $removeDestination
     * @return array|null Null on error, else data about tiling, with a boolean
     * for key "result".
     */
    public function __invoke(MediaRepresentation $media, $removeDestination = false)
    {
        if (!$media->hasOriginal()
            || strtok((string) $media->mediaType(), '/') !== 'image'
        ) {
            return null;
        }

        // Use a local sub-folder to create the tiles when Amazon s3 is used.
        // TODO Update tilers in vendor/ to manage Amazon directly.
        $subfolder = $this->params['hasAmazonS3'] ? '/tiletmp/' : '/original/';
        $sourcePath = $this->params['basePath'] . $subfolder . $media->filename();
        $storageId = $media->storageId();

        $isMissingFile = !file_exists($sourcePath) || !filesize($sourcePath);
        if ($isMissingFile && $this->params['hasAmazonS3'] && $this->params['hasArchiveRepertory']) {
            $services = $media->getServiceLocator();
            $fileManager = $services->get('ArchiveRepertory\FileManager');
            /** @var \Omeka\Entity\Media $mediaRes */
            $mediaRes = $services->get('Omeka\ApiManager')
                ->read('media', $media->id(), [], ['responseContent' => 'resource'])->getContent();

            $newStorageId = $fileManager->getStorageId($mediaRes);
            if ($storageId !== $newStorageId) {
                $storageId = $newStorageId;
                $extension = $media->extension();
                $fullExtension = strlen($extension) ? '.' . $extension : $extension;
                $sourcePath = $this->params['basePath'] . $subfolder . $newStorageId . $fullExtension;
                $isMissingFile = !file_exists($sourcePath) || !filesize($sourcePath);
            }
        }

        if ($isMissingFile) {
            $message = new Message(
                'Media #%1$d: The file "%2$s" is missing.', // @translate
                $media->id(),
                $media->filename()
            );
            $this->logger->err($message);
            return null;
        }

        // When a specific store or Archive Repertory are used, the storage id
        // may contain a subdir, so it should be added. There is no change with
        // the default simple storage id.
        $this->params['storageId'] = basename($storageId);

        $tileDir = $this->params['basePath'] . DIRECTORY_SEPARATOR . $this->params['tile_dir'];
        $tileDir = dirname($tileDir . DIRECTORY_SEPARATOR . $storageId);

        if (!is_string($removeDestination)) {
            $removeDestination = $removeDestination ? 'specific' : 'skip';
        }
        if ($removeDestination === 'all') {
            $this->getController()->tileRemover($media);
        }
        $this->params['destinationRemove'] = in_array($removeDestination, ['specific', 'all']);

        $tileBuilder = new TileBuilder();
        try {
            $result = $tileBuilder($sourcePath, $tileDir, $this->params);
        } catch (\Exception $e) {
            $message = new Message(
                'Media #%1$d: The tiler failed: %2$s', // @translate
                $media->id(),
                $e
            );
            $this->logger->err($message);
            return null;
        }

        return $result;
    }
}
