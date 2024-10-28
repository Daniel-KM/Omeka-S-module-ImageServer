<?php declare(strict_types=1);

/*
 * Copyright 2015-2024 Daniel Berthereau
 * Copyright 2016-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use ImageServer\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Media;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * Image Server
 *
 * @copyright Daniel Berthereau, 2015-2024
 * @copyright Biblibre, 2016-2017
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'IiifServer',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl
            ->allow(
                null,
                [
                    \ImageServer\Controller\ImageController::class,
                ]
            );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.63')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.63'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // The local store "files" may be hard-coded.
        $moduleConfig = include __DIR__ . '/config/module.config.php';
        $defaultSettings = $moduleConfig['imageserver']['config'];
        $tileDir = $defaultSettings['imageserver_image_tile_dir'];
        $tileDir = trim(str_replace('\\', '/', $tileDir), '/');
        if (empty($tileDir)) {
            throw new ModuleCannotInstallException((string) new PsrMessage(
                'The tile dir is not defined.' // @translate
            ));
        }

        if (!$this->checkDestinationDir($basePath . '/' . $tileDir)) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/' . $tileDir]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $messenger = $plugins->get('messenger');
        $messenger->addSuccess(new PsrMessage(
            'The tiles will be saved in the directory "{dir}".', // @translate
            ['dir' => $basePath . '/' . $tileDir]
        ));

        @copy(
            $basePath . '/' . 'index.html',
            $basePath . '/' . $tileDir . '/' . 'index.html'
        );
    }

    protected function postUninstall(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Convert all media renderer tiles into files.
        $sql = <<<SQL
UPDATE `media`
SET `renderer` = "file"
WHERE `renderer` = "tile";
SQL;
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement($sql);

        // Nuke all the tiles.
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $messenger->addWarning(new PsrMessage(
                'The tile dir is not defined and was not removed.' // @translate
            ));
        } else {
            $tileDir = $basePath . '/' . $tileDir;
            // A security check.
            $removable = $tileDir == realpath($tileDir);
            if ($removable) {
                $this->rmdir($tileDir);
            } else {
                $messenger = $services->get('ControllerPluginManager')->get('messenger');
                $messenger->addWarning(new PsrMessage(
                    'The tile dir "{dir}" is not a real path and was not removed.', // @translate
                    ['dir' => $tileDir]
                ));
            }
        }
    }

    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $message = new PsrMessage(
                'The tile dir is not defined and won’t be removed.' // @translate
            );
        } else {
            $tileDir = $basePath . '/' . $tileDir;
            $removable = $tileDir == realpath($tileDir);
            if ($removable) {
                $message = new PsrMessage('All tiles will be removed!'); // @translate
            } else {
                $message = new PsrMessage(
                    'The tile dir "{dir}" is not a real path and cannot be removed.',  // @translate
                    ['dir' => $tileDir]
                );
            }
        }

        // TODO Add a checkbox to let the choice to remove or not.
        $html = '<ul class="messages"><li class="warning">';
        $html .= '<strong>';
        $html .= 'WARNING'; // @translate
        $html .= '</strong>' . ' ';
        $html .= $message;
        $html .= '</li></ul>';
        if ($removable) {
            $html .= '<p>';
            $html .= new PsrMessage(
                'To keep the tiles, rename the dir "{dir}" before and after uninstall.', // @translate
                ['dir' => $tileDir]
            );
            $html .= '</p>';
        }
        $html .= '<p>';
        $html .= new PsrMessage(
            'All media rendered as "tile" will be rendered as "file".' // @translate
        );
        $html .= '</p>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // There are no event "api.create.xxx" for media.
        // Save dimensions before and create tiles after.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.hydrate.post',
            [$this, 'handleBeforeSaveMedia']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveItem']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'handleAfterSaveItem']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveMedia']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.post',
            [$this, 'handleAfterSaveMedia']
        );

        $sharedEventManager->attach(
            \Omeka\Entity\Media::class,
            'entity.remove.post',
            [$this, 'deleteMediaTiles']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $this->checkTilingMode();
        return $this->getConfigFormAuto($renderer);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $params = $controller->getRequest()->getPost();

        if (!$this->handleConfigFormAuto($controller)) {
            return false;
        }

        $this->checkTilingMode();

        $urlPlugin = $services->get('ViewHelperManager')->get('url');
        $top = rtrim($urlPlugin('top', [], ['force_canonical' => true]), '/') . '/';
        $settings->set('imageserver_base_url', $top);

        // Form is already validated in parent.
        $post = $params;
        $form->init();
        $form->setData($params);
        $form->isValid();
        $params = $form->getData();

        $this->normalizeMediaApiSettings($params);

        if (empty($post['imageserver_bulk_prepare']['process'])
            || empty($post['imageserver_bulk_prepare']['tasks'])
        ) {
            return true;
        }

        $params = $post['imageserver_bulk_prepare'];

        $plugins = $services->get('ControllerPluginManager');
        $messenger = $plugins->get('messenger');
        $urlPlugin = $plugins->get('url');

        $query = [];
        parse_str($params['query'], $query);
        unset($query['submit']);
        $params['query'] = $query;

        $params['remove_destination'] ??= 'skip';
        $params['update_renderer'] = empty($params['update_renderer']) ? false : $params['update_renderer'];
        $params['filter'] = empty($params['filter_sized']) ? 'all' : $params['filter_sized'];
        $params = array_intersect_key($params, [
            'query' => null,
            'tasks' => null,
            'remove_destination' => null,
            'filter' => null,
            'update_renderer' => null,
        ]);

        if (!$params['tasks']) {
            $message = new PsrMessage(
                'No task defined.' // @translate
            );
            $messenger->addError($message);
            return false;
        }

        if (in_array('tile_clean', $params['tasks']) && count($params['tasks']) > 1) {
            $message = new PsrMessage(
                'The task to clean tiles should be run alone.' // @translate
            );
            $messenger->addError($message);
            return false;
        }

        $message = null;
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        if (in_array('tile_clean', $params['tasks'])) {
            $params = array_intersect_key($params, ['query' => null, 'remove_destination' => null, 'update_renderer' => null]);
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkTileClean::class, $params);
            $message = 'Cleaning tiles and tile metadata attached to specified items, in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        } elseif (in_array('tile', $params['tasks']) && in_array('size', $params['tasks'])) {
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $params);
            $message = 'Creating tiles and dimensions for images attached to specified items, in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        } elseif (in_array('size', $params['tasks'])) {
            $params = array_intersect_key($params, ['query' => null, 'filter' => null]);
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizer::class, $params);
            $message = 'Creating dimensions for images attached to specified items, in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        } elseif (in_array('tile', $params['tasks'])) {
            $params = array_intersect_key($params, ['query' => null, 'remove_destination' => null, 'update_renderer' => null]);
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkTiler::class, $params);
            $message = 'Creating tiles for images attached to specified items, in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        } else {
            return true;
        }

        $message = new PsrMessage(
            $message,
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
        $messenger->addSuccess($message);
        return true;
    }

    protected function checkTilingMode(): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if ($settings->get('imageserver_tile_mode') !== 'manual') {
            return true;
        }
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $message = new PsrMessage(
            'The option "auto-tiling" is not set: unless you use an external image server sharing the original or a specific directory, it is recommended to enable it once all existing images have been tiled to avoid to tile new images manually.' // @translate
        );
        $messenger->addWarning($message);
        return false;
    }

    /**
     * Same in Iiif server and Image server.
     *
     * @see \IiifServer\Module::normalizeMediaApiSettings()
     * @see \ImageServer\Module::normalizeMediaApiSettings()
     */
    protected function normalizeMediaApiSettings(array $params): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Check and normalize image api versions.
        $defaultVersion = $params['iiifserver_media_api_default_version'] ?: '0';
        $has = ['1' => null, '2' => null, '3' => null];
        foreach ($params['iiifserver_media_api_supported_versions'] ?? [] as $supportedVersion) {
            $service = strtok($supportedVersion, '/');
            $level = strtok('/') ?: '0';
            $has[$service] = isset($has[$service]) && $has[$service] > $level
                ? $has[$service]
                : $level;
        }
        $has = array_filter($has);
        if ($defaultVersion && !isset($has[$defaultVersion])) {
            $has[$defaultVersion] = '0';
        }
        ksort($has);
        $supportedVersions = [];
        foreach ($has as $service => $level) {
            $supportedVersions[] = $service . '/' . $level;
        }
        $settings->set('iiifserver_media_api_default_version', $defaultVersion);
        $settings->set('iiifserver_media_api_supported_versions', $supportedVersions);

        // Avoid to do the computation each time for manifest v2, that supports
        // only one service.
        $defaultSupportedVersion = ['service' => '0', 'level' => '0'];
        foreach ($supportedVersions as $supportedVersion) {
            $service = strtok($supportedVersion, '/');
            if ($service === $defaultVersion) {
                $level = strtok('/') ?: '0';
                $defaultSupportedVersion = [
                    'service' => $service,
                    'level' => $level,
                ];
                break;
            }
        }
        $settings->set('iiifserver_media_api_default_supported_version', $defaultSupportedVersion);
    }

    public function handleBeforeSaveMedia(Event $event): void
    {
        static $imageSize;
        static $imageTypes;

        /** @var \Omeka\Entity\Media $media */
        $media = $event->getParam('entity');
        if (strtok((string) $media->getMediaType(), '/') !== 'image') {
            return;
        }

        // Check is not done on original, because in some cases, the original
        // file is removed.
        $mediaData = $media->getData() ?: [];
        $hasSize = !empty($mediaData['dimensions']['large']['width']);
        if ($hasSize) {
            return;
        }

        if (is_null($imageSize)) {
            $services = $this->getServiceLocator();
            $imageSize = $services->get('ControllerPluginManager')->get('imageSize');
            $imageTypes = array_keys($services->get('Config')['thumbnails']['types']);
            array_unshift($imageTypes, 'original');
        }

        $mediaData['dimensions'] = [];
        foreach ($imageTypes as $imageType) {
            $result = $imageSize($media, $imageType);
            $mediaData['dimensions'][$imageType] = $result;
        }

        $media->setData($mediaData);
        // No flush.
    }

    public function handleAfterSaveItem(Event $event): void
    {
        // Don't run sizing during a batch edit of items, because it runs one
        // job by item and it is slow. A batch process is always partial.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('isPartial')) {
            return;
        }

        /** @var \Omeka\Entity\Item $item */
        $item = $event->getParam('response')->getContent();

        $medias = $item->getMedia();
        if (!count($medias)) {
            return;
        }

        $services = $this->getServiceLocator();

        // This is the most common case.
        if (count($medias) === 1) {
            $this->afterSaveMedia($medias->current());
            return;
        }

        // Use bulk tiler instead of media tiler, to avoid to multiply jobs.
        $params = [
            'tasks' => ['size', 'tile'],
            'query' => ['id' => $item->getId()],
            'filter' => 'unsized',
            'remove_destination' => 'skip',
            'update_renderer' => false,
        ];

        /** @var \Omeka\Job\Dispatcher $dispatcher */
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        // The tile mode may be "auto", "manual" or "external".
        $tileMode = $services->get('Omeka\Settings')->get('imageserver_tile_mode', 'auto');
        $tileMode === 'auto'
            ? $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $params)
            : $dispatcher->dispatch(\ImageServer\Job\BulkSizer::class, $params);
    }

    public function handleAfterSaveMedia(Event $event): void
    {
        // Don't run sizing during a batch edit of media, because it runs one
        // job by media and it is slow. A batch process is always partial.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('isPartial')) {
            return;
        }

        $media = $event->getParam('response')->getContent();
        $this->afterSaveMedia($media);
    }

    /**
     * Save dimensions of a media and create tiles.
     *
     * @param Media $media
     */
    protected function afterSaveMedia(Media $media): void
    {
        static $processingMedia = [];

        if (strtok((string) $media->getMediaType(), '/') !== 'image'
            || !empty($processingMedia[$media->getId()])
            // For ingester bulk_upload, wait that the process is finished, else
            // the thumbnails won't be available and the size of derivative will
            // be the fallback ones.
            // Anyway, the process is now launched from the job bulk upload.
            || $media->getIngester() === 'bulk_upload'
        ) {
            return;
        }

        $processingMedia[$media->getId()] = true;

        $services = $this->getServiceLocator();
        $mediaRepr = $services->get('Omeka\ApiAdapterManager')->get('media')->getRepresentation($media);
        /** @var \ImageServer\Mvc\Controller\Plugin\TileInfo $tileMediaInfo */
        $tileMediaInfo = $services->get('ControllerPluginManager')->get('tileMediaInfo');

        // A quick check to avoid a useless job.
        // Check is not done on original, because in some cases, the original
        // file is removed.
        $mediaData = $media->getData() ?: [];
        $hasSize = !empty($mediaData['dimensions']['large']['width']);
        $hasTile = $tileMediaInfo($mediaRepr);
        $isTileModeAuto = $services->get('Omeka\Settings')->get('imageserver_tile_mode', 'auto') === 'auto';
        if ($hasSize && ($hasTile || !$isTileModeAuto)) {
            return;
        }

        $tasks = [];
        if (!$hasSize) {
            $tasks[] = 'size';
        }
        if (!$hasTile && $isTileModeAuto) {
            $tasks[] = 'tile';
        }

        // Media sizer and tiler.
        $params = [
            'tasks' => $tasks,
            'query' => ['id' => $media->getId()],
            'filter' => 'all',
            'remove_destination' => 'all',
            'update_renderer' => false,
        ];

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        if (!$hasSize && !$hasTile) {
            $dispatcher->dispatch(\ImageServer\Job\MediaSizerAndTiler::class, $params);
        } elseif (!$hasSize) {
            $params = array_intersect_key($params, ['query' => null, 'filter' => null]);
            $dispatcher->dispatch(\ImageServer\Job\MediaSizer::class, $params);
        } else {
            $params = array_intersect_key($params, ['query' => null, 'remove_destination' => null, 'update_renderer' => null]);
            $dispatcher->dispatch(\ImageServer\Job\MediaTiler::class, $params);
        }
    }

    /**
     * Delete all tiles associated with a removed Media entity.
     */
    public function deleteMediaTiles(Event $event): void
    {
        /** @var \ImageServer\Mvc\Controller\Plugin\TileRemover $tileRemover */
        $services = $this->getServiceLocator();
        $tileRemover = $services->get('ControllerPluginManager')->get('tileRemover');
        $media = $event->getTarget();
        $tileRemover($media);
    }
}
