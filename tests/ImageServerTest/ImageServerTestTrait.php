<?php declare(strict_types=1);

namespace ImageServerTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Job;

/**
 * Shared test helpers for ImageServer module tests.
 */
trait ImageServerTestTrait
{
    /**
     * @var bool Whether admin is logged in.
     */
    protected bool $isLoggedIn = false;

    /**
     * @var array IDs of items created during tests (for cleanup).
     */
    protected array $createdResources = [];

    /**
     * @var int[] IDs of media rows inserted via SQL (for cleanup).
     */
    protected array $createdMediaIds = [];

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        if ($this->isLoggedIn) {
            $this->ensureLoggedIn();
        }
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the database connection.
     */
    protected function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $this->ensureLoggedIn();
    }

    /**
     * Ensure admin is logged in on the current application instance.
     */
    protected function ensureLoggedIn(): void
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        if ($auth->hasIdentity()) {
            return;
        }

        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Create a test item via the API.
     *
     * @param array $data Item data with property terms as keys.
     * @return ItemRepresentation
     */
    protected function createItem(array $data = []): ItemRepresentation
    {
        $response = $this->api()->create('items', $data);
        $item = $response->getContent();
        $this->createdResources[] = [
            'type' => 'items',
            'id' => $item->id(),
        ];
        return $item;
    }

    /**
     * Insert a media row directly via SQL (resource + media tables).
     *
     * Doctrine uses JOINED table inheritance for resources: the
     * `media` table's `id` column is a foreign key to `resource.id`.
     * This method inserts into both tables.
     *
     * @return int The inserted media/resource id.
     */
    protected function insertMediaRow(
        int $itemId,
        string $ingester,
        string $renderer,
        string $mediaType = 'image/jpeg',
        ?array $data = null
    ): int {
        $connection = $this->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Insert into parent resource table first.
        $connection->executeStatement(
            'INSERT INTO `resource`'
            . ' (`resource_type`, `is_public`, `created`, `modified`)'
            . ' VALUES (:type, 1, :now, :now)',
            ['type' => 'Omeka\Entity\Media', 'now' => $now]
        );
        $resourceId = (int) $connection->lastInsertId();

        // Insert into media table with the same id.
        $storageId = bin2hex(random_bytes(20));
        $dataJson = $data !== null
            ? json_encode($data, JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE)
            : null;
        $connection->executeStatement(
            'INSERT INTO `media`'
            . ' (`id`, `item_id`, `ingester`, `renderer`,'
            . ' `media_type`, `storage_id`, `position`,'
            . ' `has_original`, `has_thumbnails`,'
            . ' `source`, `data`)'
            . ' VALUES (:id, :itemId, :ingester, :renderer,'
            . ' :mediaType, :storageId, 1,'
            . ' 0, 0,'
            . ' :source, :data)',
            [
                'id' => $resourceId,
                'itemId' => $itemId,
                'ingester' => $ingester,
                'renderer' => $renderer,
                'mediaType' => $mediaType,
                'storageId' => $storageId,
                'source' => 'test.jpg',
                'data' => $dataJson,
            ]
        );

        $this->createdMediaIds[] = $resourceId;
        return $resourceId;
    }

    /**
     * Read ingester and renderer for a media row.
     */
    protected function readMediaIngesterRenderer(int $mediaId): array
    {
        $connection = $this->getConnection();
        $sql = 'SELECT `ingester`, `renderer` FROM `media`'
            . ' WHERE `id` = :id';
        $row = $connection->fetchAssociative($sql, ['id' => $mediaId]);
        return $row ?: [];
    }

    /**
     * Read the raw data column for a media.
     */
    protected function readMediaData(int $mediaId): ?array
    {
        $connection = $this->getConnection();
        $sql = 'SELECT `data` FROM `media` WHERE `id` = :id';
        $raw = $connection->fetchOne($sql, ['id' => $mediaId]);
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * Delete a media row from both media and resource tables.
     */
    protected function deleteMediaRow(int $mediaId): void
    {
        $connection = $this->getConnection();
        $connection->executeStatement(
            'DELETE FROM `media` WHERE `id` = :id',
            ['id' => $mediaId]
        );
        $connection->executeStatement(
            'DELETE FROM `resource` WHERE `id` = :id',
            ['id' => $mediaId]
        );
    }

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError Whether to expect an error.
     * @return Job
     */
    protected function runJob(
        string $jobClass,
        array $args,
        bool $expectError = false
    ): Job {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($auth->getIdentity());

        $entityManager->persist($job);
        $entityManager->flush();

        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new \DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new \DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete media rows inserted via SQL.
        foreach ($this->createdMediaIds as $mediaId) {
            try {
                $this->deleteMediaRow($mediaId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdMediaIds = [];

        // Delete items created via API.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete(
                    $resource['type'],
                    $resource['id']
                );
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];
    }
}
