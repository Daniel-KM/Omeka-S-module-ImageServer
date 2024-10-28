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
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.63')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.63'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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

    $urlPlugin = $services->get('ControllerPluginManager')->get('url');
    $top = rtrim($urlPlugin->fromRoute('top', [], ['force_canonical' => true]), '/') . '/';
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
    // During upgrade, the jobs are not available.
    require_once dirname(__DIR__, 2) . '/src/Job/SizerTrait.php';
    require_once dirname(__DIR__, 2) . '/src/Job/TilerTrait.php';
    require_once dirname(__DIR__, 2) . '/src/Job/BulkSizerAndTiler.php';
    $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
    $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $args);

    $message = new PsrMessage(
        'Storing tile info for images in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}). This process will take a while.', // @translate
        [
            'link' => sprintf('<a href="%s">',
                htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            'job_id' => $job->getId(),
            'link_end' => '</a>',
            'link_log' => sprintf('<a href="%s">',
                htmlspecialchars($this->isModuleActive('Log')
                    ? $urlPlugin->fromRoute('admin/log', [], ['query' => ['job_id' => $job->getId()]])
                    : $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log'])
                )
            ),
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
            ['module' => $module, 'version' => $version]
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
    $this->checkAutoTiling();
}

if (version_compare($oldVersion, '3.6.10.3', '<')) {
    $modules = [
        ['name' => 'ArchiveRepertory', 'version' => '3.15.4', 'required' => false],
        ['name' => 'IiifServer', 'version' => '3.6.6.6', 'required' => true],
    ];
    foreach ($modules as $moduleData) {
        if (($moduleData['required'] && !$this->isModuleVersionAtLeast($moduleData['name'], $moduleData['version']))
            || (!$moduleData['required'] && $this->isModuleActive($module) && !$this->isModuleVersionAtLeast($moduleData['name'], $moduleData['version']))
        ) {
            $translator = $services->get('MvcTranslator');
            $message = new PsrMessage(
                'This module requires the module "{module}", version {version} or above.', // @translate
                ['module' => $moduleName, 'version' => $version]
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
