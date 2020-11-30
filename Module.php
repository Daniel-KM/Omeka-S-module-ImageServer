<?php declare(strict_types=1);

/*
 * Copyright 2015-2020 Daniel Berthereau
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

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Entity\Media;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    // TODO Remove dependency to IIIF Server.
    protected $dependency = 'IiifServer';

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
                    \ImageServer\Controller\MediaController::class,
                ]
            );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $t = $services->get('MvcTranslator');

        $module = $moduleManager->getModule('Generic');
        if ($module && version_compare($module->getIni('version'), '3.3.26', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Generic', '3.3.26'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $checkDeepzoom = __DIR__ . '/vendor/daniel-km/deepzoom/src/DeepzoomFactory.php';
        $checkZoomify = __DIR__ . '/vendor/daniel-km/zoomify/src/ZoomifyFactory.php';
        if (!file_exists($checkDeepzoom) || !file_exists($checkZoomify)) {
            throw new ModuleCannotInstallException(
                $t->translate('You should run "composer install" from the root of the module, or install a release with the dependencies.') // @translate
                    . ' ' . $t->translate('See module’s installation documentation.') // @translate
            );
        }

        $module = $moduleManager->getModule('ArchiveRepertory');
        if ($module) {
            $version = $module->getDb('version');
            // Check if installed.
            if (empty($version)) {
                // Nothing to do.
            } elseif (version_compare($version, '3.15.4', '<')) {
                throw new ModuleCannotInstallException(
                    $t->translate('This version requires Archive Repertory 3.15.4 or greater (used for some 3D views).') // @translate
                );
            }
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $this->createTilesMainDir($services);
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
        $connection->exec($sql);

        // Nuke all the tiles.
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $messenger = new Messenger();
            $messenger->addWarning('The tile dir is not defined and was not removed.'); // @translate
        } else {
            $tileDir = $basePath . '/' . $tileDir;
            // A security check.
            $removable = $tileDir == realpath($tileDir);
            if ($removable) {
                $this->rrmdir($tileDir);
            } else {
                $messenger = new Messenger();
                $messenger->addWarning(
                    'The tile dir "%s" is not a real path and was not removed.', // @translate
                    $tileDir
                );
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
            $message = new Message('The tile dir is not defined and won’t be removed.'); // @translate
        } else {
            $tileDir = $basePath . '/' . $tileDir;
            $removable = $tileDir == realpath($tileDir);
            if ($removable) {
                $message = 'All tiles will be removed!'; // @translate
            } else {
                $message = new Message('The tile dir "%d" is not a real path and cannot be removed.', $tileDir); // @translate
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
            $html .= new Message(
                'To keep the tiles, rename the dir "%s" before and after uninstall.', // @translate
                $tileDir
            );
            $html .= '</p>';
        }
        $html .= '<p>';
        $html .= new Message(
            'All media rendered as "tile" will be rendered as "file".' // @translate
        );
        $html .= '</p>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );

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
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (!parent::handleConfigForm($controller)) {
            return false;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $top = rtrim($urlHelper('top', [], ['force_canonical' => true]), '/') . '/';
        $settings->set('imageserver_base_url', $top);

        // Form is already validated in parent.
        $params = (array) $controller->getRequest()->getPost();
        if (empty($params['imageserver_bulk_prepare']['process'])
            || empty($params['imageserver_bulk_prepare']['tasks'])
        ) {
            return true;
        }

        $params = $params['imageserver_bulk_prepare'];

        $plugins = $services->get('ControllerPluginManager');
        $messenger = $plugins->get('messenger');
        $urlHelper = $plugins->get('url');

        $query = [];
        parse_str($params['query'], $query);
        unset($query['submit']);
        $params['query'] = $query;

        $params['remove_destination'] = $params['remove_destination'];
        $params['update_renderer'] = empty($params['update_renderer']) ? false : $params['update_renderer'];
        $params['filter'] = empty($params['filter_sized']) ? 'all' : $params['filter_sized'];
        $params = array_intersect_key($params, ['query' => null, 'tasks' => null, 'remove_destination' => null, 'filter' => null, 'update_renderer' => null]);

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        if (in_array('tile', $params['tasks']) && in_array('size', $params['tasks'])) {
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $params);
        } elseif (in_array('size', $params['tasks'])) {
            $params = array_intersect_key($params, ['query' => null, 'filter' => null]);
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkSizer::class, $params);
        } elseif (in_array('tile', $params['tasks'])) {
            $params = array_intersect_key($params, ['query' => null, 'remove_destination' => null, 'update_renderer' => null]);
            $job = $dispatcher->dispatch(\ImageServer\Job\BulkTiler::class, $params);
        } else {
            return true;
        }

        $message = new Message(
            'Creating tiles and/or dimensions for images attached to specified items, in background (%1$sjob #%2$d%3$s, %4$slogs%3$s).', // @translate
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
        $messenger->addSuccess($message);
        return true;
    }

    protected function createTilesMainDir(ServiceLocatorInterface $services): void
    {
        // The local store "files" may be hard-coded.
        $config = include __DIR__ . '/config/module.config.php';
        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $defaultSettings['imageserver_image_tile_dir'];
        if (empty($tileDir)) {
            throw new ModuleCannotInstallException(new Message(
                'The tile dir is not defined.', // @translate
                $tileDir
            ));
        }

        $dir = $basePath . '/' . $tileDir;

        // Check if the directory exists in the archive.
        if (file_exists($dir)) {
            if (!is_dir($dir)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'The directory "%s" cannot be created: a file exists.', // @translate
                    $dir
                ));
            }
            if (!is_writeable($dir)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'The directory "%s" is not writeable.', // @translate
                    $dir
                ));
            }
        } else {
            $result = mkdir($dir, 0755, true);
            if (!$result) {
                throw new ModuleCannotInstallException((string) new Message(
                    'The directory "%s" cannot be created.', // @translate
                    $dir
                ));
            }
        }

        $messenger = new Messenger();
        $messenger->addSuccess(new Message(
            'The tiles will be saved in the directory "%s".', // @translate
            $dir
        ));

        @copy(
            $basePath . '/' . 'index.html',
            $dir . '/' . 'index.html'
        );
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

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $services->get('Omeka\Settings')->get('imageserver_auto_tile', false)
            ? $dispatcher->dispatch(\ImageServer\Job\BulkSizerAndTiler::class, $params)
            : $dispatcher->dispatch(\ImageServer\Job\BulkSizer::class, $params);
    }

    public function handleAfterSaveMedia(Event $event): void
    {
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
        if (strtok((string) $media->getMediaType(), '/') !== 'image') {
            return;
        }

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
        $autoTile = $services->get('Omeka\Settings')->get('imageserver_auto_tile', false);
        if ($hasSize && ($hasTile || !$autoTile)) {
            return;
        }

        $tasks = [];
        if (!$hasSize) {
            $tasks[] = 'size';
        }
        if (!$hasTile && $autoTile) {
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
     *
     * @param Event $event
     */
    public function deleteMediaTiles(Event $event): void
    {
        $services = $this->getServiceLocator();
        $tileRemover = $services->get('ControllerPluginManager')->get('tileRemover');
        $media = $event->getTarget();
        $tileRemover($media);
    }

    /**
     * Removes directories recursively.
     *
     * @param string $dir Directory name.
     * @return bool
     */
    private function rrmdir($dir)
    {
        if (!file_exists($dir)
            || !is_dir($dir)
            || !is_readable($dir)
            || !is_writable($dir)
        ) {
            return false;
        }

        $scandir = scandir($dir);
        if (!is_array($scandir)) {
            return false;
        }

        $files = array_diff($scandir, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
