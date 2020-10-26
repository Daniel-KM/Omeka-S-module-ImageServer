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
     * @var bool $removeDestination
     * @return array|bool False on error, else data about tiling, with a boolean
     * for key "result".
     */
    public function __invoke(MediaRepresentation $media, $removeDestination = false)
    {
        if (!$media->hasOriginal()
            || strtok($media->mediaType(), '/') !== 'image'
        ) {
            return false;
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
                'The file "%s" of media #%d is missing', // @translate
                $media->filename(),
                $media->id()
            );
            $this->logger->err($message);
            return false;
        }

        $params = $this->params;

        // When a specific store or Archive Repertory are used, the storage id
        // may contain a subdir, so it should be added. There is no change with
        // the default simple storage id.
        $params['storageId'] = basename($storageId);

        $tileDir = $params['basePath'] . DIRECTORY_SEPARATOR . $params['tile_dir'];
        $tileDir = dirname($tileDir . DIRECTORY_SEPARATOR . $storageId);

        $params['destinationRemove'] = $removeDestination;

        $tileBuilder = new TileBuilder();
        try {
            $result = $tileBuilder($sourcePath, $tileDir, $params);
        } catch (\Exception $e) {
            $message = new Message(
                'The tiler failed: %s', // @translate
                $e
            );
            $this->logger->err($message);
            return false;
        }

        return $result;
    }
}
