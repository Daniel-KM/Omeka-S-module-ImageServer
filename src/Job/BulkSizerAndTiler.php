<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Job\AbstractJob;

class BulkSizerAndTiler extends AbstractJob
{
    use SizerTrait;
    use TilerTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

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
     * @var \ImageServer\Mvc\Controller\Plugin\TileInfo
     */
    protected $tileInfo;

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('imageserver/bulk-sizer-tiler/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);
        $this->entityManager = $services->get('Omeka\EntityManager');
        $api = $services->get('Omeka\ApiManager');

        $tasks = array_intersect($this->getArg('tasks', ['size', 'tile']), ['size', 'tile', 'tile_info']);
        if (empty($tasks)) {
            $this->logger->warn(
                'The job ended: no tasks (tile or size) defined.' // @translate
            );
            return;
        }

        $query = $this->getArg('query', []);

        $response = $api->search('items', $query);
        $this->totalToProcess = $response->getTotalResults();
        if (empty($this->totalToProcess)) {
            $this->logger->warn(
                'No item selected. You may check your query.' // @translate
            );
            return;
        }

        if (in_array('size', $tasks)) {
            $this->prepareSizer();
        }

        if (in_array('tile', $tasks)) {
            $this->prepareTiler();
        } elseif (in_array('tile_info', $tasks)) {
            $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
            $this->tileInfo = $services->get('ControllerPluginManager')->get('tileInfo');
        }

        $this->logger->info(
            'Starting bulk tiling or sizing for {total} items.', // @translate
            ['total' => $this->totalToProcess]
        );

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

            $doIds ??= [];
            foreach ($items as $key => $item) {
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job "Bulk Tiler and Sizer" was stopped: {count}/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $this->totalToProcess]
                    );
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if (strtok((string) $media->mediaType(), '/') !== 'image'
                        // For ingester bulk_upload, wait that the process is
                        // finished, else the thumbnails won't be available and
                        // the size of derivative will be the fallback ones.
                        || $media->ingester() === 'bulk_upload'
                    ) {
                        unset($media);
                        continue;
                    }

                    ++$this->totalImages;
                    $this->processResource($media, $tasks);
                    unset($media);
                }

                // Collect DigitalObjects referenced by this item via values.
                foreach ($item->values() as $property) {
                    foreach ($property['values'] as $value) {
                        $vr = $value->valueResource();
                        if ($vr && $vr->resourceName() === 'digital_objects') {
                            $doIds[$vr->id()] = true;
                        }
                    }
                }
                unset($item);

                ++$this->totalProcessed;
            }

            $this->logger->info(
                '{count}/{total} items processed.', // @translate
                ['count' => $this->totalProcessed, 'total' => $this->totalToProcess]
            );

            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        // Second pass: digital objects referenced by the matched items.
        if (!empty($doIds)) {
            $doIds = array_keys($doIds);
            foreach (array_chunk($doIds, self::SQL_LIMIT) as $chunk) {
                if ($this->shouldStop()) {
                    break;
                }
                try {
                    $dos = $api->search('digital_objects', ['id' => $chunk])->getContent();
                } catch (\Omeka\Api\Exception\BadRequestException $e) {
                    break;
                }
                foreach ($dos as $do) {
                    if ($this->shouldStop()) {
                        break 2;
                    }
                    if (strtok((string) $do->mediaType(), '/') !== 'image') {
                        unset($do);
                        continue;
                    }
                    ++$this->totalImages;
                    $this->processResource($do, $tasks);
                    unset($do);
                }
                $this->entityManager->clear();
            }
        }

        $this->logger->notice(
            'End of bulk sizing and tiling: {count}/{total} items processed, {total_images} images checked, {total_succeed} processed, {total_skipped} already done (skipped), {total_failed} errors.', // @translate
            [
                'count' => $this->totalProcessed,
                'total' => $this->totalToProcess,
                'total_succeed' => $this->totalSucceed,
                'total_failed' => $this->totalFailed,
                'total_skipped' => $this->totalSkipped,
                'total_images' => $this->totalImages,
            ]
        );
    }

    /**
     * This task is only used during upgrade: tile info are automatically filled
     * after tiling.
     *
     * @param MediaRepresentation $media
     */
    protected function processResource(\Omeka\Api\Representation\AbstractResourceEntityRepresentation $media, array $tasks): void
    {
        $skipped = $this->totalSkipped;
        $failed = $this->totalFailed;
        $succeed = $this->totalSucceed;

        if (in_array('size', $tasks)) {
            $this->prepareSize($media);
        }

        if (in_array('tile', $tasks)) {
            $this->prepareTile($media);
        } elseif (in_array('tile_info', $tasks)) {
            $this->prepareTileInfo($media);
        }

        // Avoid double counting skip/fail/success.
        if ($this->totalSkipped !== $skipped) {
            $this->totalSkipped = ++$skipped;
        }
        if ($this->totalFailed !== $failed) {
            $this->totalFailed = ++$failed;
        }
        if ($this->totalSucceed !== $succeed) {
            $this->totalSucceed = ++$succeed;
        }
    }

    protected function prepareTileInfo(\Omeka\Api\Representation\AbstractResourceEntityRepresentation $media): void
    {
        $this->logger->info(
            'Media #{media_id}: Store tile info', // @translate
            ['media_id' => $media->id()]
        );

        $mediaData = $media->mediaData();
        if ($mediaData === null) {
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
        $this->entityManager->flush();
    }
}
