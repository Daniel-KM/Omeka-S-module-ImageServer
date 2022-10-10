<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Entity\Media;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;
use Omeka\Api\Representation\MediaRepresentation;

class BulkTileInfo extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var \ImageServer\Mvc\Controller\Plugin\TileInfo
     */
    protected $tileInfo;

    /**
     * @var int
     */
    protected $totalImages;

    /**
     * @var int
     */
    protected $totalProcessed;

    /**
     * @var int
     */
    protected $totalToProcess;

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

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
        $this->tileInfo = $services->get('ControllerPluginManager')->get('tileInfo');
        $api = $services->get('Omeka\ApiManager');

        $query = $this->getArg('query', []);

        $response = $api->search('items', ['limit' => 0] + $query);
        $this->totalToProcess = $response->getTotalResults();
        if (empty($this->totalToProcess)) {
            $this->logger->warn(new Message(
                'No item selected. You may check your query.' // @translate
            ));
            return;
        }

        $this->logger->info(new Message(
            'Starting bulk tile info for %d items.', // @translate
            $this->totalToProcess
        ));

        $offset = 0;
        $this->totalImages = 0;
        $this->totalProcessed = 0;
        $this->totalSucceed = 0;
        $this->totalFailed = 0;
        $this->totalSkipped = 0;
        while (true) {
            /** @var \Omeka\Api\Representation\ItemRepresentation[] $items */
            $items = $api
                ->search('items', ['limit' => self::SQL_LIMIT, 'offset' => $offset] + $query)
                ->getContent();
            if (empty($items)) {
                break;
            }

            foreach ($items as $key => $item) {
                if ($this->shouldStop()) {
                    $this->logger->warn(new Message(
                        'The job "Bulk Tile Info" was stopped: %1$d/%2$d resources processed.', // @translate
                        $offset + $key, $this->totalToProcess
                    ));
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if (strtok((string) $media->mediaType(), '/') !== 'image') {
                        unset($media);
                        continue;
                    }
                    ++$this->totalImages;
                    $this->prepareTileInfo($media);
                    unset($media);
                }
                unset($item);

                ++$this->totalProcessed;
            }

            // Flush one time each loop.
            $this->entityManager->flush();
            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(new Message(
            'End of bulk prepare tile info: %1$d/%2$d items processed, %3$d files processed, %4$d errors, %5$d skipped on a total of %6$d images.', // @translate
            $this->totalProcessed,
            $this->totalToProcess,
            $this->totalSucceed,
            $this->totalFailed,
            $this->totalSkipped,
            $this->totalImages
        ));
    }

    protected function prepareTileInfo(MediaRepresentation $media): void
    {
        $mediaData = $media->mediaData();
        if (is_null($mediaData)) {
            $mediaData = ['tile' => []];
        } else {
            $mediaData['tile'] = [];
        }

        $formats = [
            'deepzoom',
            'zoomify',
            'jpeg2000',
            'tiled_tiff',
        ];
        foreach ($formats as $format) {
            $result = $this->tileInfo->__invoke($media, $format);
            if ($result) {
                $mediaData['tile'][$format] = $result;
            }
        }

        $mediaEntity = $this->mediaRepository->find($media->id());
        $mediaEntity->setData($mediaData);
        $this->entityManager->persist($mediaEntity);
        // No flush here.
    }
}
