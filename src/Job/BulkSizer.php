<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Job\AbstractJob;

class BulkSizer extends AbstractJob
{
    use SizerTrait;

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

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('imageserver/bulk-sizer/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);
        $api = $services->get('Omeka\ApiManager');

        $query = $this->getArg('query', []);
        $scope = (array) $this->getArg('scope', ['items', 'digital_objects']);
        $scope = array_values(array_intersect($scope, ['items', 'digital_objects']));
        if (!$scope) {
            $this->logger->warn(
                'No scope selected (items / digital objects). Nothing to do.' // @translate
            );
            return;
        }
        $doItems = in_array('items', $scope, true);
        $doDigitalObjects = in_array('digital_objects', $scope, true);

        $this->prepareSizer();

        $this->totalToProcess = 0;
        $this->totalImages = 0;
        $this->totalProcessed = 0;
        $this->totalSucceed = 0;
        $this->totalFailed = 0;
        $this->totalSkipped = 0;

        $response = $api->search('items', $query);
        $this->totalToProcess = $response->getTotalResults();
        if (empty($this->totalToProcess)) {
            $this->logger->warn(
                'No item selected. You may check your query.' // @translate
            );
            return;
        }

        $this->logger->info(
            'Starting bulk sizing for {total} items ({filter} media, scope: {scope}).', // @translate
            ['total' => $this->totalToProcess, 'filter' => $this->filter, 'scope' => implode(', ', $scope)]
        );

        // Collect ids of digital objects referenced by items, to deduplicate
        // across items (a DO can be shared by many items).
        $doIds = [];

        $offset = 0;
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
                    $this->logger->warn(
                        'The job "Bulk Sizer" was stopped: {count}/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $this->totalToProcess]
                    );
                    break 2;
                }

                if ($doItems) {
                    /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                    foreach ($item->media() as $media) {
                        if (strtok((string) $media->mediaType(), '/') === 'image'
                            // For ingester bulk_upload, wait that the process
                            // is finished, else the thumbnails won't be
                            // available and the size of derivative will be the
                            // fallback.
                            && $media->ingester() !== 'bulk_upload'
                        ) {
                            ++$this->totalImages;
                            $this->prepareSize($media);
                        }
                        unset($media);
                    }
                }

                if ($doDigitalObjects) {
                    foreach ($item->values() as $property) {
                        foreach ($property['values'] as $value) {
                            $vr = $value->valueResource();
                            if ($vr && $vr->resourceName() === 'digital_objects') {
                                $doIds[$vr->id()] = true;
                            }
                        }
                    }
                }

                unset($item);

                ++$this->totalProcessed;
            }

            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        // Second pass: digital objects referenced by the matched items.
        if ($doDigitalObjects && $doIds) {
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
                    if (strtok((string) $do->mediaType(), '/') === 'image') {
                        ++$this->totalImages;
                        $this->prepareSize($do);
                    }
                    unset($do);
                }
                $this->entityManager->clear();
            }
        }

        $this->logger->notice(
            'End of bulk sizing: {count}/{total} items processed, {total_images} images checked, {total_succeed} sized, {total_skipped} already sized (skipped), {total_failed} errors.', // @translate
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
}
