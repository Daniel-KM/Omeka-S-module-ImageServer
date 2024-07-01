<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Api\Representation\MediaRepresentation;

trait TilerTrait
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \ImageServer\Mvc\Controller\Plugin\Tiler
     */
    protected $tiler;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var bool
     */
    protected $setRendererTile;

    /**
     * @var string
     */
    protected $removeDestination;

    /**
     * @var string|bool
     */
    protected $updateRenderer;

    /**
     * @var string
     */
    protected $tileType;

    /**
     * @var int
     */
    protected $totalSucceed;

    /**
     * @var int
     */
    protected $totalFailed;

    /**
     * @var int
     */
    protected $totalSkipped;

    protected function prepareTiler(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->tiler = $services->get('ControllerPluginManager')->get('tiler');
        // The api cannot update value "renderer", so use entity manager.
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);

        $this->removeDestination = $this->getArg('remove_destination', 'skip') ?: 'skip';
        $this->updateRenderer = $this->getArg('update_renderer', false) ?: false;
        $this->tileType = $services->get('Omeka\Settings')->get('imageserver_image_tile_type', '');
    }

    protected function prepareTile(MediaRepresentation $media): void
    {
        if (!$media->hasOriginal()
            || strtok((string) $media->mediaType(), '/') !== 'image'
            // For ingester bulk_upload, wait that the process is finished, else
            // the thumbnails won't be available and the size of derivative will
            // be the fallback ones.
            || $media->ingester() === 'bulk_upload'
        ) {
            return;
        }

        $this->logger->info(
            'Media #{media_id}: Start tiling ({type})', // @translate
            ['media_id' => $media->id(), 'type' => $this->tileType]
        );

        $result = $this->tiler->__invoke($media, $this->removeDestination);

        if ($result && !empty($result['result'])) {
            if (!empty($result['skipped'])) {
                $this->logger->info(
                    'Media #{media_id}: Skipped because already tiled.', // @translate
                    ['media_id' => $media->id()]
                );
                ++$this->totalSkipped;
            } else {
                $renderer = $media->renderer();
                if ($this->updateRenderer && $renderer !== $this->updateRenderer) {
                    /** @var \Omeka\Entity\Media $mediaEntity  */
                    $mediaEntity = $this->mediaRepository->find($media->id());
                    $mediaEntity->setRenderer($this->updateRenderer);
                    $this->entityManager->persist($mediaEntity);
                    $this->entityManager->flush();
                    unset($mediaEntity);
                    $this->logger->info(
                        'Media #{media_id}: Renderer "{renderer}" updated to "{renderer_new}".', // @translate
                        ['media_id' => $media->id(), 'renderer' => $renderer, 'renderer_new' => $this->updateRenderer]
                    );
                }
                $this->logger->info(
                    'Media #{media_id}: End tiling', // @translate
                    ['media_id' => $media->id()]
                );
                ++$this->totalSucceed;
            }
        } else {
            $this->logger->err(
                'Media #{media_id}: Error during tiling', // @translate
                ['media_id' => $media->id()]
            );
            ++$this->totalFailed;
        }
    }
}
