<?php declare(strict_types=1);

namespace ImageServer;

use Omeka\Stdlib\Message;

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
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.6.2', '<')) {
    $settings->set('imageserver_info_default_version', $settings->get('imageserver_manifest_version'));
    $settings->delete('imageserver_manifest_version');
    $settings->set('imageserver_info_version_append', false);
}

if (version_compare($oldVersion, '3.6.3.3', '<')) {
    $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
    if ($module && version_compare($module->getIni('version') ?? '', '3.3.27', '<')) {
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
            'Generic', '3.3.27'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $message = new Message(
        'Now, all images can be automatically converted into tiles and an option in settings and site settings allows to specify the default display.
It can be selected directly in the theme too (thumbnail "tile").
The conversion of the renderer from "tile" to the standard "file" can be done with the job in the config form.' // @translate
    );
    $messenger->addWarning($message);

    $settings->set('imageserver_imager', $settings->get('imageserver_image_creator') ?: 'Auto');
    $settings->delete('imageserver_image_creator');

    $urlHelper = $services->get('ViewHelperManager')->get('url');
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
    // During upgrade, the jobs are not available.
    require_once dirname(__DIR__, 2) . '/src/Job/SizerTrait.php';
    require_once dirname(__DIR__, 2) . '/src/Job/TilerTrait.php';
    require_once dirname(__DIR__, 2) . '/src/Job/BulkSizerAndTiler.php';
    $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
    $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $args);

    $urlHelper = $services->get('ControllerPluginManager')->get('url');
    $message = new Message(
        'Storing tile info for images in background (%1$sjob #%2$d%3$s, %4$slogs%3$s). This process will take a while.', // @translate
        sprintf('<a href="%s">',
            htmlspecialchars($urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
        ),
        $job->getId(),
        '</a>',
        sprintf('<a href="%s">',
            htmlspecialchars($this->isModuleActive('Log')
                ? $urlHelper->fromRoute('admin/log', [], ['query' => ['job_id' => $job->getId()]])
                : $urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log'])
            )
        )
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.6.7.3', '<')) {
    $module = $services->get('Omeka\ModuleManager')->getModule('IiifServer');
    if (!$module || version_compare($module->getIni('version') ?? '', '3.6.5.3', '<')) {
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
            'IiifServer', '3.6.5.3'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $settings->set('iiifserver_media_api_url', '');
    // Renamed "iiifserver_media_api_default_version".
    $settings->delete('imageserver_info_default_version');
    // Renamed "iiifserver_media_api_version_append".
    $settings->delete('imageserver_info_version_append');
    //  Renamed "iiifserver_media_api_prefix".
    $settings->delete('imageserver_identifier_prefix');

    $message = new Message(
        'The routes to the image server have been renamed from "iiif-img/" and "ixif-media/" to the more standard "iiif/".' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'Check the config of the module.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.6.9.3', '<')) {
    $this->checkAutoTiling();
}

if (version_compare($oldVersion, '3.6.10.3', '<')) {
    $modules = [
        ['name' => 'Generic', 'version' => '3.3.34', 'required' => false],
        ['name' => 'ArchiveRepertory', 'version' => '3.15.4', 'required' => false],
        ['name' => 'IiifServer', 'version' => '3.6.6.6', 'required' => true],
    ];
    foreach ($modules as $moduleData) {
        if (method_exists($this, 'checkModuleAvailability')) {
            $this->checkModuleAvailability($moduleData['name'], $moduleData['version'], $moduleData['required'], true);
        } else {
            // @todo Adaptation from Generic method, to be removed in next version.
            $moduleName = $moduleData['name'];
            $version = $moduleData['version'];
            $required = $moduleData['required'];
            $module = $services->get('Omeka\ModuleManager')->getModule($moduleName);
            if (!$module || !$this->isModuleActive($moduleName)) {
                if (!$required) {
                    continue;
                }
                // Else throw message below (required module with a version or not).
            } elseif (!$version || version_compare($module->getIni('version') ?? '', $version, '>=')) {
                continue;
            }
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                $moduleName, $version
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }
}
