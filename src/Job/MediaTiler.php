<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class MediaTiler extends AbstractJob
{
    use TilerTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        $query = $this->getArg('query', []);
        $query['limit'] = 1;

        $response = $api->search('media', $query);
        $totalToProcess = $response->getTotalResults();
        if (empty($totalToProcess)) {
            $this->logger->warn(new Message(
                'No media selected. You may check your query.' // @translate
            ));
            return;
        }

        $media = $response->getContent();

        /** @var \Omeka\Api\Representation\MediaRepresentation $media */
        $media = reset($media);
        if (strtok((string) $media->mediaType(), '/') !== 'image') {
            return;
        }

        $this->prepareTiler();
        $this->prepareTile($media);
    }
}
