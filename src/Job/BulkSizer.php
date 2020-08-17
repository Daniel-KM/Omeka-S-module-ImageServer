<?php
namespace ImageServer\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class BulkSizer extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    public function perform()
    {
        /**
         * @var array $config
         * @var \Omeka\Mvc\Controller\Plugin\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $sizer = $services->get('ControllerPluginManager')->get('imageSize');
        // The api cannot update value "data", so use entity manager.
        $entityManager = $services->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);

        $query = $this->getArg('query', []);

        $response = $api->search('items', $query);
        $totalToProcess = $response->getTotalResults();
        if (empty($totalToProcess)) {
            $logger->warn(new Message(
                'No item selected. You may check your query.' // @translate
            ));
            return;
        }

        $filter = $this->getArg('filter', 'all');
        if (!in_array($filter, ['all', 'sized', 'unsized'])) {
            $filter = 'all';
        }

        $imageTypes = array_keys($services->get('Config')['thumbnails']['types']);
        array_unshift($imageTypes, 'original');

        $logger->info(new Message(
            'Starting bulk sizing for %1$d items (%2$s media).', // @translate
            $totalToProcess, $filter
        ));

        $offset = 0;
        $totalImages = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
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
                    $logger->warn(new Message(
                        'The job "Bulk Sizer" was stopped: %1$d/%2$d resources processed.', // @translate
                        $offset + $key, $totalToProcess
                    ));
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $mediaRepr */
                foreach ($item->media() as $mediaRepr) {
                    if (strtok($mediaRepr->mediaType(), '/') === 'image') {
                        ++$totalImages;
                        // Keep possible data added by another module.
                        $mediaData = $mediaRepr->mediaData() ?: [];
                        if ($filter === 'sized') {
                            if (empty($mediaData['dimensions']['large']['width'])) {
                                ++$totalSkipped;
                                continue;
                            }
                        } elseif ($filter === 'unsized') {
                            if (!empty($mediaData['dimensions']['large']['width'])) {
                                ++$totalSkipped;
                                continue;
                            }
                        }

                        $logger->info(new Message(
                            'Starting sizing media #%d.', // @translate
                            $mediaRepr->id()
                        ));

                        /** @var \Omeka\Entity\Media $media */
                        $media = $repository->find($mediaRepr->id());

                        // Reset dimensions to make the sizer working.
                        // TODO In some cases, the original file is removed once the thumbnails are built.
                        $mediaData['dimensions'] = [];
                        $media->setData($mediaData);

                        $failedTypes = [];
                        foreach ($imageTypes as $imageType) {
                            $result = $sizer($media, $imageType);
                            if (!array_filter($result)) {
                                $failedTypes[] = $imageType;
                            }
                            $mediaData['dimensions'][$imageType] = $result;
                        }
                        if (count($failedTypes)) {
                            $logger->err(new Message(
                                'Error getting dimensions of media #%1$d for types "%2$s".', // @translate
                                $media->getId(),
                                implode('", "', $failedTypes)
                            ));
                            ++$totalFailed;
                        }

                        $media->setData($mediaData);
                        $entityManager->persist($media);
                        $entityManager->flush();
                        ++$totalSucceed;
                    }
                    unset($mediaRepr);
                    unset($media);
                }
                unset($item);

                ++$totalProcessed;
            }

            $entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $logger->info(new Message(
            'End of bulk sizing: %1$d/%2$d items processed, %3$d files tiled, %4$d errors, %5$d skipped on a total of %6$d images.', // @translate
            $totalProcessed,
            $totalToProcess,
            $totalSucceed,
            $totalFailed,
            $totalSkipped,
            $totalImages
        ));
    }
}
