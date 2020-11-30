<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\Controller\PluginManager as ControllerPlugins;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Stdlib\Message;

class Tiler extends AbstractPlugin
{
    /**
     * @var array
     */
    protected $params;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var ControllerPlugins
     */
    protected $controllerPlugins;

    /**
     * @param array $params
     * @param EntityManager $entityManager
     * @param ControllerPlugins $controllerPlugins
     */
    public function __construct(
        array $params,
        EntityManager $entityManager,
        ControllerPlugins $controllerPlugins
    ) {
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->controllerPlugins = $controllerPlugins;
    }

    /**
     * Tile a media.
     *
     * @var MediaRepresentation $media
     * @var string|bool $removeDestination
     * @return array|null Null on error, else data about tiling, with a boolean
     * for key "result".
     */
    public function __invoke(MediaRepresentation $media, $removeDestination = false): ?array
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
            $this->controllerPlugins->get('logger')->__invoke()->err($message);
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
            /** @var \Omeka\Entity\Media $mediaEntity  */
            $mediaEntity = $this->entityManager->find(\Omeka\Entity\Media::class, $media->id());
            $this->controllerPlugins->get('tileRemover')->__invoke($mediaEntity);
            $this->removeMediaTleData($media);
        }
        $this->params['destinationRemove'] = in_array($removeDestination, ['specific', 'all']);

        $tileBuilder = $this->controllerPlugins->get('tileBuilder');
        try {
            $result = $tileBuilder($sourcePath, $tileDir, $this->params);
        } catch (\Exception $e) {
            $message = new Message(
                'Media #%1$d: The tiler failed: %2$s', // @translate
                $media->id(),
                $e
            );
            $this->controllerPlugins->get('logger')->__invoke()->err($message);
            return null;
        }

        $this->addMediaTleData($media, $this->params);

        return $result;
    }

    protected function removeMediaTleData(MediaRepresentation $media): void
    {
        /** @var \Omeka\Entity\Media $mediaEntity  */
        $mediaEntity = $this->entityManager->find(\Omeka\Entity\Media::class, $media->id());
        $mediaData = $mediaEntity->getData();
        if (empty($mediaData) || !array_key_exists('tile', $mediaData)) {
            return;
        }

        unset($mediaData['tile']);
        $mediaEntity->setData($mediaData);
        $this->entityManager->persist($mediaEntity);
        $this->entityManager->flush();
    }

    protected function addMediaTleData(MediaRepresentation $media, array $params): void
    {
        /** @var \Omeka\Entity\Media $mediaEntity  */
        $mediaEntity = $this->entityManager->find(\Omeka\Entity\Media::class, $media->id());
        $mediaData = $mediaEntity->getData();
        if (is_null($mediaData)) {
            $mediaData = ['tile' => []];
        } elseif (empty($mediaData['tile'])) {
            $mediaData['tile'] = [];
        }

        $result = $this->controllerPlugins->get('tileInfo')->__invoke($media, $params['tile_type']);
        if ($result) {
            $mediaData['tile'][$params['tile_type']] = $result;
            // Set the new tile type the first.
            $mediaData['tile'] = array_replace([$params['tile_type'] => null], $mediaData['tile']);
        } else {
            unset($mediaData['tile'][$params['tile_type']]);
        }

        $mediaEntity->setData($mediaData);
        $this->entityManager->persist($mediaEntity);
        $this->entityManager->flush();
    }
}
