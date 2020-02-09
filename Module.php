<?php

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
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    // TODO Remove dependency to IIIF Server.
    protected $dependency = 'IiifServer';

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event)
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

    protected function preInstall()
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $t = $services->get('MvcTranslator');

        $checkDeepzoom = __DIR__
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'daniel-km'
            . DIRECTORY_SEPARATOR . 'deepzoom'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'DeepzoomFactory.php';
        $checkZoomify = __DIR__
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'daniel-km'
            . DIRECTORY_SEPARATOR . 'zoomify'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'ZoomifyFactory.php';
        if (!file_exists($checkDeepzoom) || !file_exists($checkZoomify)) {
            throw new ModuleCannotInstallException(
                $t->translate('You should run "composer install" from the root of the module, or install a release with the dependencies.') // @translate
                    . ' ' . $t->translate('See module’s installation documentation.') // @translate
            );
        }

        $processors = $this->listImageProcessors($services);
        if (empty($processors)) {
            throw new ModuleCannotInstallException(
                $t->translate('The module requires an image processor (ImageMagick, Imagick or GD).') // @translate
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

    protected function postInstall()
    {
        $services = $this->getServiceLocator();
        $this->createTilesMainDir($services);
    }

    protected function postUninstall()
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Nuke all the tiles.
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $messenger = new Messenger();
            $messenger->addWarning('The tile dir is not defined and was not removed.'); // @translate
        } else {
            $tileDir = $basePath . DIRECTORY_SEPARATOR . $tileDir;

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

    public function warnUninstall(Event $event)
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');

        $basePath = $serviceLocator->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $message = new Message('The tile dir is not defined and won’t be removed.'); // @translate
        } else {
            $tileDir = $basePath . DIRECTORY_SEPARATOR . $tileDir;
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
        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );

        $sharedEventManager->attach(
            \Omeka\Entity\Media::class,
            'entity.remove.post',
            [$this, 'deleteMediaTiles']
        );
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (!parent::handleConfigForm($controller)) {
            return false;
        }

        // Form is already validated in parent.
        $params = (array) $controller->getRequest()->getPost();
        $params = array_intersect_key($params, ['query' => null, 'remove_destination' => null, 'process' => null]);
        if (empty($params['process']) || $params['process'] !== $controller->translate('Process')) {
            return;
        }

        if (empty($params['query'])) {
            $message = 'A query is needed to run the bulk tiler.'; // @translate
            $controller->messenger()->addWarning($message);
            return;
        }

        $query = [];
        parse_str($params['query'], $query);
        unset($query['submit']);
        $params['query'] = $query;

        unset($params['process']);

        $services = $this->getServiceLocator();
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\ImageServer\Job\BulkTiler::class, $params);
        $message = new Message(
            'Creating tiles for images attached to specified items, in background (%sjob #%d%s)', // @translate
            sprintf('<a href="%s">',
                htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $controller->messenger()->addSuccess($message);
    }

    /**
     * Check and return the list of available image processors.
     *
     * @param ServiceLocatorInterface $services
     * @return array Associative array of available image processors.
     */
    protected function listImageProcessors(ServiceLocatorInterface $services)
    {
        $translator = $services->get('MvcTranslator');

        $processors = [];
        $processors['Auto'] = $translator->translate('Automatic'); // @translate
        if (extension_loaded('gd')) {
            $processors['GD'] = 'GD (php extension)'; // @translate
        }
        if (extension_loaded('imagick')) {
            $processors['Imagick'] = 'Imagick (php extension)'; // @translate
        }
        // TODO Check if ImageMagick cli is available.
        $processors['ImageMagick'] = 'ImageMagick (command line)'; // @translate
        return $processors;
    }

    protected function createTilesMainDir(ServiceLocatorInterface $serviceLocator)
    {
        // The local store "files" may be hard-coded.
        $config = include __DIR__ . '/config/module.config.php';
        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        $basePath = $serviceLocator->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $defaultSettings['imageserver_image_tile_dir'];
        if (empty($tileDir)) {
            throw new ModuleCannotInstallException(new Message(
                'The tile dir is not defined.', // @translate
                $tileDir
            ));
        }

        $dir = $basePath . DIRECTORY_SEPARATOR . $tileDir;

        // Check if the directory exists in the archive.
        if (file_exists($dir)) {
            if (!is_dir($dir)) {
                throw new ModuleCannotInstallException(new Message(
                    'The directory "%s" cannot be created: a file exists.', // @translate
                    $dir
                ));
            }
            if (!is_writeable($dir)) {
                throw new ModuleCannotInstallException(new Message(
                    'The directory "%s" is not writeable.', // @translate
                    $dir
                ));
            }
        } else {
            $result = mkdir($dir, 0755, true);
            if (!$result) {
                throw new ModuleCannotInstallException(new Message(
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
            $basePath . DIRECTORY_SEPARATOR . 'index.html',
            $dir . DIRECTORY_SEPARATOR . 'index.html'
        );
    }

    /**
     * Delete all tiles associated with a removed Media entity.
     *
     * @param Event $event
     */
    public function deleteMediaTiles(Event $event)
    {
        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');
        $basePath = $serviceLocator->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            $logger = $serviceLocator->get('Omeka\logger');
            $logger->err(new Message('Tile dir is not defined, so media tiles cannot be removed.')); // @translate
            return;
        }

        $tileDir = $basePath . DIRECTORY_SEPARATOR . $tileDir;

        // Remove all files and folders, whatever the format or the source.
        $media = $event->getTarget();
        $storageId = $media->getStorageId();
        $filepath = $tileDir . DIRECTORY_SEPARATOR . $storageId . '.dzi';
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        $filepath = $tileDir . DIRECTORY_SEPARATOR . $storageId . '.js';
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        $filepath = $tileDir . DIRECTORY_SEPARATOR . $storageId . '_files';
        if (file_exists($filepath) && is_dir($filepath)) {
            $this->rrmdir($filepath);
        }
        $filepath = $tileDir . DIRECTORY_SEPARATOR . $storageId . '_zdata';
        if (file_exists($filepath) && is_dir($filepath)) {
            $this->rrmdir($filepath);
        }
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
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
