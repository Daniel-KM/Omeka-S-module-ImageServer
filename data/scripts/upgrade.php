<?php declare(strict_types=1);

namespace ImageServer;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Translate $translate
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$urlHelper = $services->get('ViewHelperManager')->get('url');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

/**
 * Dispatch a background job during module upgrade.
 *
 * During upgrade, module classes are not yet available to the background
 * process because the module state in the database is still
 * "needs_upgrade". This function temporarily sets the module version and
 * active flag so the spawned process can bootstrap the module, waits for
 * the job to start, then restores the original state. The Module Manager
 * will set the real version and state once upgrade() returns.
 *
 * @see \IiifServer upgrade.php
 */
$dispatchJobDuringUpgrade = function (string $jobClass, array $args = [])
    use ($services, $connection, $newVersion, $messenger): \Omeka\Entity\Job {
    $moduleId = 'ImageServer';

    // Load all required job files.
    $baseJobPath = dirname(__DIR__, 2) . '/src/Job/';
    foreach (['SizerTrait', 'TilerTrait'] as $trait) {
        $file = $baseJobPath . $trait . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
    $shortClass = substr(
        strrchr('\\' . $jobClass, '\\'), 1
    );
    require_once $baseJobPath . $shortClass . '.php';

    // Read current state.
    $moduleRow = $connection->executeQuery(
        'SELECT is_active FROM module WHERE id = :id',
        ['id' => $moduleId]
    )->fetchAssociative();
    $wasActive = (bool) ($moduleRow['is_active'] ?? false);

    // Temporarily mark the module as active with the new version so the
    // background process bootstraps it.
    $connection->executeStatement(
        'UPDATE module SET version = :version, is_active = 1 WHERE id = :id',
        ['version' => $newVersion, 'id' => $moduleId]
    );

    $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
    $job = $dispatcher->dispatch($jobClass, $args);

    // Wait for the background process to bootstrap (read the module state)
    // before restoring.
    sleep(5);

    // Check whether the job actually started.
    $jobId = $job->getId();
    $status = $connection->executeQuery(
        'SELECT status FROM job WHERE id = :id',
        ['id' => $jobId]
    )->fetchOne();
    if ($status === \Omeka\Entity\Job::STATUS_STARTING) {
        $messenger->addWarning(new PsrMessage(
            'The job #{job_id} is still starting after the sleep delay. It may need to be relaunched manually.', // @translate
            ['job_id' => $jobId]
        ));
    }

    // Restore is_active if the module was inactive. The version is not
    // restored: the Module Manager overwrites it after upgrade() returns.
    if (!$wasActive) {
        $connection->executeStatement(
            'UPDATE module SET is_active = 0 WHERE id = :id',
            ['id' => $moduleId]
        );
    }

    return $job;
};

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.84')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module {module} should be upgraded to version {version} or later.'), // @translate
        'Common', '3.4.84'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '3.6.2', '<')) {
    $settings->set('imageserver_info_default_version', $settings->get('imageserver_manifest_version'));
    $settings->delete('imageserver_manifest_version');
    $settings->set('imageserver_info_version_append', false);
}

if (version_compare($oldVersion, '3.6.3.3', '<')) {
    $message = new PsrMessage(
        'Now, all images can be automatically converted into tiles and an option in settings and site settings allows to specify the default display.
It can be selected directly in the theme too (thumbnail "tile").
The conversion of the renderer from "tile" to the standard "file" can be done with the job in the config form.' // @translate
    );
    $messenger->addWarning($message);

    $settings->set('imageserver_imager', $settings->get('imageserver_image_creator') ?: 'Auto');
    $settings->delete('imageserver_image_creator');

    $top = rtrim($urlHelper('top', [], ['force_canonical' => true]), '/') . '/';
    $settings->set('imageserver_base_url', $top);

    $settings->set('imageserver_auto_tile', false);

    $args = [
        'tasks' => [
            'size',
            'tile_info',
        ],
        'query' => [],
        'filter' => 'all',
    ];
    $job = $dispatchJobDuringUpgrade(
        \ImageServer\Job\BulkSizerAndTiler::class, $args
    );

    $message = new PsrMessage(
        'Storing tile info for images in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}). This process will take a while.', // @translate
        [
            'link' => sprintf('<a href="%s">',
                htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            'job_id' => $job->getId(),
            'link_end' => '</a>',
            'link_log' => class_exists('Log\Module', false)
                ? sprintf('<a href="%1$s">', $urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                : sprintf('<a href="%1$s" target="_blank">', $urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.6.7.3', '<')) {
    $module = $services->get('Omeka\ModuleManager')->getModule('IiifServer');
    if (!$module || version_compare($module->getIni('version') ?? '', '3.6.5.3', '<')) {
        $translator = $services->get('MvcTranslator');
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'IiifServer', 'version' => '3.6.5.3']
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    $settings->set('iiifserver_media_api_url', '');
    // Renamed "iiifserver_media_api_default_version".
    $settings->delete('imageserver_info_default_version');
    // Renamed "iiifserver_media_api_version_append".
    $settings->delete('imageserver_info_version_append');
    //  Renamed "iiifserver_media_api_prefix".
    $settings->delete('imageserver_identifier_prefix');

    $message = new PsrMessage(
        'The routes to the image server have been renamed from "iiif-img/" and "ixif-media/" to the more standard "iiif/".' // @translate
    );
    $messenger->addWarning($message);
    $message = new PsrMessage(
        'Check the config of the module.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.6.9.3', '<')) {
    $this->checkTilingMode();
}

if (version_compare($oldVersion, '3.6.10.3', '<')) {
    $modules = [
        ['name' => 'ArchiveRepertory', 'version' => '3.15.4', 'required' => false],
        ['name' => 'IiifServer', 'version' => '3.6.6.6', 'required' => true],
    ];
    foreach ($modules as $moduleData) {
        if (($moduleData['required'] && !$this->isModuleVersionAtLeast($moduleData['name'], $moduleData['version']))
            || (!$moduleData['required'] && $this->isModuleActive($moduleData['name']) && !$this->isModuleVersionAtLeast($moduleData['name'], $moduleData['version']))
        ) {
            $translator = $services->get('MvcTranslator');
            $message = new PsrMessage(
                'This module requires the module "{module}", version {version} or above.', // @translate
                ['module' => $moduleData['name'], 'version' => $moduleData['version']]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }
    }
}

if (version_compare($oldVersion, '3.6.13', '<')) {
    $settings->set('imageserver_tile_manual', !$settings->get('imageserver_auto_tile'));
    $settings->delete('imageserver_auto_tile');
}

if (version_compare($oldVersion, '3.6.18', '<')) {
    $isTileManual = $settings->get('imageserver_tile_manual');
    $settings->set('imageserver_tile_mode', $isTileManual ? 'manual' : 'auto');
    $settings->delete('imageserver_tile_manual');
    if ($isTileManual) {
        $message = new PsrMessage(
            'A new option allows to set the tile mode "external". Check it if you use an external image server.' // @translate
        );
        $messenger->addWarning($message);
    }

    $message = new PsrMessage(
        'A new task allows to clear tile metadata, that may be useful when an external server is used.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.6.22', '<')) {
    // The "tile" ingester was disabled in v3.6.13, but existing media were
    // never converted. It has no impact once ingested, since only renderer is
    // used. Nevertheless, convert remaining ingester to "upload" and renderer
    // to "file".

    $sql = <<<'SQL'
        UPDATE `media`
        SET `ingester` = "upload"
        WHERE `ingester` IN ("tile", "file");
        SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
        UPDATE `media`
        SET `renderer` = "file"
        WHERE `renderer` = "tile";
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.6.23', '<')) {
    if (!$this->checkModuleActiveVersion('IiifServer', '3.6.29')) {
        $message = new PsrMessage(
            'The module {module} should be upgraded to version {version} or later.', // @translate
            ['module' => 'IiifServer', 'version' => '3.6.29']
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }
}

if (version_compare($oldVersion, '3.6.24', '<')) {
    if (!$this->checkModuleActiveVersion('IiifServer', '3.6.31')) {
        $message = new PsrMessage(
            'The module {module} should be upgraded to version {version} or later.', // @translate
            ['module' => 'IiifServer', 'version' => '3.6.31']
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }

    // Create the tile cache directory for the fast tile script.
    $config = $services->get('Config');
    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    $tileDir = $settings->get('imageserver_image_tile_dir', 'tile');
    $cachePath = $basePath . '/' . $tileDir . '/cache';
    if (!is_dir($cachePath)) {
        @mkdir($cachePath, 0775, true);
    }

    // Auto-select best vips mode when still on Auto.
    $imager = $settings->get('imageserver_imager', 'Auto');
    $hasPhpVips = false;
    if (class_exists('Jcupitt\Vips\Image')) {
        try {
            \Jcupitt\Vips\Image::black(1, 1);
            $hasPhpVips = true;
        } catch (\Throwable $e) {
        }
    }
    $hasVipsCli = (bool) trim(
        (string) shell_exec('which vips 2>/dev/null')
    );
    if ($imager === 'Auto' && $hasPhpVips) {
        $settings->set('imageserver_imager', 'PhpVips');
        $message = new PsrMessage(
            'php-vips detected and set as default image processor (fastest).' // @translate
        );
        $messenger->addSuccess($message);
    } elseif ($imager === 'Auto' && $hasVipsCli) {
        $settings->set('imageserver_imager', 'Vips');
        $message = new PsrMessage(
            'Vips CLI detected and set as default image processor. For better performance, install php-vips (composer require jcupitt/vips).' // @translate
        );
        $messenger->addSuccess($message);
    }

    // Warn about deprecated thumbnailer.
    $alias = $config['service_manager']['aliases']['Omeka\File\Thumbnailer'] ?? null;
    if ($alias === 'ImageServer\File\Thumbnailer\Vips') {
        $message = new PsrMessage(
            'The vips thumbnailer from ImageServer has been removed. Remove the alias "Omeka\File\Thumbnailer" from your file config/local.config.php. Then, install and enable the module Vips.' // @translate
        );
        $messenger->addError($message);
    }

    // Reduce default max size for non-vips imagers (was 10 MB).
    $maxSize = (int) $settings->get('imageserver_image_max_size');
    if ($maxSize === 10000000) {
        $settings->set('imageserver_image_max_size', 5000000);
    }

    // Keep deepzoom as default: static files served directly by
    // Apache (~0.5ms) without PHP. Tiled TIFF is more compact but
    // requires PHP+vips for each tile request.

    // Warn about EXIF rotation and tiling.
    $message = new PsrMessage(
        'Images with EXIF rotation (orientations 5-8, typically photos from phones) may have been tiled before the rotation was applied, resulting in incorrectly oriented tiles. If you have such images, re-run the bulk tiler from the module config form to fix them.' // @translate
    );
    $messenger->addWarning($message);
}
