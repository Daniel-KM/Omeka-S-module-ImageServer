<?php declare(strict_types=1);

/*
 * Copyright 2015-2026 Daniel Berthereau
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

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
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
use Omeka\Mvc\Controller\Plugin\Messenger;

/**
 * Image Server
 *
 * @copyright Daniel Berthereau, 2015-2026
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
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.82')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.82'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $errors = [];

        if (!$this->checkModuleActiveVersion('IiifServer', '3.6.31')) {
            $errors[] = (string) new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Iiif Server', '3.6.31'
            );
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // The local store "files" may be hard-coded.
        $moduleConfig = include __DIR__ . '/config/module.config.php';
        $defaultSettings = $moduleConfig['imageserver']['config'];
        $tileDir = $defaultSettings['imageserver_image_tile_dir'];
        $tileDir = trim(strtr($tileDir, ['\\' => '/']), '/');
        if (empty($tileDir)) {
            $errors[] = (string) new PsrMessage(
                'The tile dir is not defined.' // @translate
            );
        } elseif (!$this->checkDestinationDir($basePath . '/' . $tileDir)) {
            $errors[] = (string) new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/' . $tileDir]
            );
        }

        // Create the tile cache directory for the fast tile script.
        $cachePath = $basePath . '/' . $tileDir . '/cache';
        if ($this->checkDestinationDir($cachePath) && !$this->checkDestinationDir($cachePath)) {
            $errors[] = (string) new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $cachePath]
            );
        }

        if ($errors) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(implode("\n", $errors));
        }
        $plugins = $services->get('ControllerPluginManager');
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

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $hasPhpVips = $this->isPhpVipsAvailable();
        $hasVipsCli = (bool) trim(
            (string) shell_exec('which vips 2>/dev/null')
        );

        // Auto-select best imager.
        if ($hasPhpVips) {
            $settings->set('imageserver_imager', 'PhpVips');
            $messenger->addSuccess(new PsrMessage(
                'php-vips detected and set as default image processor (fastest).' // @translate
            ));
        } elseif ($hasVipsCli) {
            $settings->set('imageserver_imager', 'Vips');
            $messenger->addSuccess(new PsrMessage(
                'Vips CLI detected and set as default image processor. For better performance, install php-vips (composer require jcupitt/vips).' // @translate
            ));
        }

        // Keep deepzoom as default tile type: static files served
        // directly by Apache (~0.5ms per tile) without PHP.

        // Set base url.
        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $top = rtrim($urlHelper('top', [], ['force_canonical' => true]), '/') . '/';
        $settings->set('imageserver_base_url', $top);
    }

    protected function postUninstall(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Remove the fast tile script rule from .htaccess.
        $this->removeFastTileHtaccess();

        // Convert all media tile ingesters and renderers into upload/file.
        $connection = $services->get('Omeka\Connection');
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
        $removable = false;
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
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $messenger = $plugins->get('messenger');

        // Clear any previous messages, then run diagnostics.
        $messenger->clear();
        $this->runDiagnostics();

        // Collect diagnostic messages and clear them from the flash
        // messenger so they appear only in the audit tab.
        $diagnostics = $messenger->get();
        $messenger->clear();

        $settings = $services->get('Omeka\Settings');
        $formManager = $services->get('FormElementManager');

        $renderer->ckEditor();

        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');
        if ($data === null) {
            return null;
        }

        $view = $renderer;
        $translate = $view->plugin('translate');
        $escape = $view->plugin('escapeHtml');

        $form = $formManager->get(ConfigForm::class);
        $form->init();

        // Update bulk note with current tile type.
        $tileTypeLabels = [
            'deepzoom' => $translate('Deep Zoom Image'), // @translate
            'zoomify' => $translate('Zoomify'), // @translate
            'jpeg2000' => $translate('Jpeg 2000'), // @translate
            'tiled_tiff' => $translate('Tiled tiff'), // @translate
        ];
        $currentTileType = $settings->get('imageserver_image_tile_type', 'deepzoom');
        $bulkFieldset = $form->get('imageserver_bulk_prepare');
        $note = $bulkFieldset->get('note');
        $noteText = $translate('Run tiling and/or dimension sizing for existing images via a background job. Dimensions are needed for the IIIF info.json. Tiles improve zoom performance in viewers.'); // @translate
        $noteText .= ' ' . new PsrMessage(
            'Current tile format: {format}.', // @translate
            ['format' => $tileTypeLabels[$currentTileType] ?? $currentTileType]
        );
        $note->setOption('text', $noteText);
        $form->setData($data);
        $form->prepare();

        // --- Audit tab ---
        $levelClasses = [
            Messenger::ERROR => 'error',
            Messenger::SUCCESS => 'success',
            Messenger::WARNING => 'warning',
            Messenger::NOTICE => 'notice',
        ];
        $auditHtml = '';
        foreach ($diagnostics as $type => $messages) {
            $class = $levelClasses[$type] ?? 'notice';
            foreach ($messages as $msg) {
                $text = $msg instanceof PsrMessage
                    ? ($msg->escapeHtml() === false ? (string) $msg : $escape((string) $msg))
                    : $escape((string) $msg);
                $auditHtml .= '<p class="' . $class . '">' . $text . '</p>';
            }
        }
        if (!$auditHtml) {
            $auditHtml = '<p class="success">'
                . $escape($translate('All checks passed.'))
                . '</p>';
        }

        // --- Collect elements by group ---
        $elementGroups = $form->getOption('element_groups') ?: [];
        $elementsInGroups = [];
        $elementsNotInGroups = [];
        foreach ($form as $element) {
            if ($element instanceof \Laminas\Form\FieldsetInterface) {
                continue;
            }
            $group = $element->getOption('element_group');
            if ($group && isset($elementGroups[$group])) {
                $elementsInGroups[$group][] = $element;
            } else {
                $elementsNotInGroups[] = $element;
            }
        }

        // Render a group as a fieldset with legend.
        $renderGroup = function (string $groupName) use ($elementsInGroups, $elementGroups, $escape, $translate, $view): string {
            if (empty($elementsInGroups[$groupName])) {
                return '';
            }
            $html = sprintf(
                '<fieldset id="%s"><legend>%s</legend>',
                $escape($groupName),
                $escape($translate($elementGroups[$groupName]))
            );
            foreach ($elementsInGroups[$groupName] as $el) {
                $html .= $view->formRow($el);
            }
            return $html . '</fieldset>';
        };

        // --- Configuration tab: infra + tiling ---
        $configHtml = '';
        foreach ($elementsNotInGroups as $el) {
            $configHtml .= $view->formRow($el);
        }
        $configHtml .= $renderGroup('infra');
        $configHtml .= $renderGroup('tiling');

        // --- Metadata tab ---
        $metadataHtml = $renderGroup('metadata');

        // --- Bulk tab ---
        $bulkFieldset = $form->get('imageserver_bulk_prepare');
        $bulkHtml = $view->formCollection($bulkFieldset);

        // --- Module navigation bar ---
        $iiifModules = ['IiifServer', 'ImageServer', 'IiifSearch'];
        $moduleNav = $view->moduleConfigNav($iiifModules, 'ImageServer');

        // --- Tabbed layout ---
        return $moduleNav
            . '<ul class="section-nav" style="list-style:none;padding:0;">'
            . '<li class="active"><a href="#imageserver-audit">'
            . $escape($translate('Audit'))
            . '</a></li>'
            . '<li><a href="#imageserver-config">'
            . $escape($translate('Configuration'))
            . '</a></li>'
            . '<li><a href="#imageserver-metadata">'
            . $escape($translate('Metadata'))
            . '</a></li>'
            . '<li><a href="#imageserver-bulk">'
            . $escape($translate('Bulk processing'))
            . '</a></li>'
            . '</ul>'
            . '<div id="imageserver-audit" class="section active">'
            . $auditHtml
            . '</div>'
            . '<div id="imageserver-config" class="section">'
            . $configHtml
            . '</div>'
            . '<div id="imageserver-metadata" class="section">'
            . $metadataHtml
            . '</div>'
            . '<div id="imageserver-bulk" class="section">'
            . $bulkHtml
            . '</div>';
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

        $this->runDiagnostics();

        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $top = rtrim($urlHelper('top', [], ['force_canonical' => true]), '/') . '/';
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

        $query = [];
        parse_str($params['query'], $query);
        unset($query['submit']);
        $params['query'] = $query;

        $params['remove_destination'] ??= 'skip';
        $params['update_renderer'] = false;
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
     * Run all infrastructure diagnostics via messenger.
     * Messages are retrieved by getConfigForm() for the audit tab.
     */
    protected function runDiagnostics(): void
    {
        // When an external image server handles image requests,
        // most local diagnostics are irrelevant.
        if ($this->checkExternalServer()) {
            $this->checkDeprecatedThumbnailer();
            return;
        }

        $this->checkTilingMode();
        $this->checkImager();
        $this->checkTileType();
        $this->checkDeprecatedThumbnailer();
        $this->checkTilingStatus();
        $this->checkFastTileScript();
        $this->checkDirectories();
        $this->checkPhpMemory();
        $this->checkIiifVersion();
        $this->checkRights();
        $this->checkBaseUrl();
        $this->checkHttp2();
        $this->checkOpcache();
    }

    /**
     * Check if an external image server is configured.
     *
     * @return bool True if external server is active (skip local
     * diagnostics).
     */
    protected function checkExternalServer(): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $externalUrl = $settings->get('iiifserver_media_api_url');
        if (empty($externalUrl)) {
            return false;
        }

        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');
        $message = new PsrMessage(
            'An external image server is configured ({url}). Local image processing, tiling and performance diagnostics are skipped.', // @translate
            ['url' => $externalUrl]
        );
        $messenger->addSuccess($message);
        return true;
    }

    /**
     * Recommend tiled tiff when vips is available.
     */
    /**
     * Check tile type and recommend the best option.
     *
     * - Deepzoom: tiles are static files served by Apache (~0.5ms).
     *   Best for serving performance. Works with any imager. Many
     *   small files on disk (thousands per image).
     * - Tiled TIFF: single file per image, vips extracts regions
     *   instantly (~10ms). Better for storage and creation speed.
     *   Requires vips for extraction and goes through PHP.
     */
    protected function checkTileType(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $tileType = $settings->get('imageserver_image_tile_type', 'deepzoom');
        $hasVips = $this->isPhpVipsAvailable()
            || (bool) trim((string) shell_exec('which vips 2>/dev/null'));

        if ($tileType === 'tiled_tiff' && !$hasVips) {
            $message = new PsrMessage(
                'Tile type is "tiled tiff" but vips is not available. Only vips can extract regions from tiled TIFF. Switch to "deepzoom" or install vips.' // @translate
            );
            $messenger->addError($message);
        } elseif ($tileType === 'tiled_tiff') {
            $message = new PsrMessage(
                'Tile type is "tiled tiff": compact storage (single file per image), requires vips for each tile request (~10ms via PHP). For faster tile serving (~0.5ms), use "deepzoom" (static files served by Apache).' // @translate
            );
            $messenger->addSuccess($message);
        } elseif ($tileType === 'deepzoom') {
            $message = new PsrMessage(
                'Tile type is "deepzoom": fastest tile serving (static files, ~0.5ms via Apache). Creates many small files on disk.' // @translate
            );
            $messenger->addSuccess($message);
        }
    }

    /**
     * Check if vips thumbnailer is referenced in config to force module Vips.
     */
    protected function checkDeprecatedThumbnailer(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $alias = $config['service_manager']['aliases']['Omeka\File\Thumbnailer'] ?? null;
        if ($alias === 'ImageServer\File\Thumbnailer\Vips') {
            $message = new PsrMessage(
                'The vips thumbnailer from ImageServer has been removed. Remove the alias "Omeka\File\Thumbnailer" from your file config/local.config.php. Then, install and enable the module Vips.' // @translate
            );
            $messenger->addError($message);
        }
    }

    /**
     * Check which image processor is available and auto-select vips.
     */
    protected function checkImager(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $imager = $settings->get('imageserver_imager', 'Auto');

        $hasVipsCli = (bool) trim(
            (string) shell_exec('which vips 2>/dev/null')
        );
        $hasPhpVips = $this->isPhpVipsAvailable();
        $hasImagick = extension_loaded('imagick');
        $hasGd = extension_loaded('gd');

        $available = [];
        if ($hasVipsCli) {
            $version = trim(
                (string) shell_exec('vips --version 2>/dev/null')
            );
            $available[] = 'vips CLI (' . $version . ')';
            if (version_compare($version, 'vips-8.10', '<')) {
                $message = new PsrMessage(
                    'Vips version {version} is older than 8.10. Upgrade is recommended for full feature support.', // @translate
                    ['version' => $version]
                );
                $messenger->addWarning($message);
            }
        }
        if ($hasPhpVips) {
            $available[] = 'php-vips';
        }
        if ($hasImagick) {
            $available[] = 'Imagick';
        }
        if ($hasGd) {
            $available[] = 'GD';
        }

        $message = new PsrMessage(
            'Available image processors: {list}.', // @translate
            ['list' => implode(', ', $available) ?: 'none']
        );
        $messenger->addSuccess($message);

        // Auto-select best vips mode when current setting is Auto.
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
        } elseif (!in_array($imager, ['PhpVips', 'Vips', 'Auto']) && ($hasPhpVips || $hasVipsCli)) {
            $message = new PsrMessage(
                'Vips is available but not selected as image processor. It is recommended for best performance and memory efficiency.' // @translate
            );
            $messenger->addWarning($message);
        }

        if (!$hasVipsCli && !$hasPhpVips) {
            $message = new PsrMessage(
                'Vips is not installed. Install libvips-tools (apt install libvips-tools) or php-vips (composer require jcupitt/vips) for best performance.' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Check tile coverage: count tiled vs untiled image media.
     */
    protected function checkTilingStatus(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        // Count image media with and without tile data.
        $totalImages = (int) $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM media
            WHERE media_type LIKE 'image/%'
                AND media_type != 'image/svg+xml'
            SQL
        );
        if (!$totalImages) {
            return;
        }

        // Tile info is stored in data.tile (deepzoom, zoomify,
        // tiled_tiff, or jpeg2000).
        $tiledImages = (int) $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM media
            WHERE media_type LIKE 'image/%'
                AND media_type != 'image/svg+xml'
                AND JSON_EXTRACT(data, '$.tile') IS NOT NULL
            SQL
        );

        $untiled = $totalImages - $tiledImages;
        if ($untiled === 0) {
            $message = new PsrMessage(
                'All {total} images are tiled.', // @translate
                ['total' => $totalImages]
            );
            $messenger->addSuccess($message);
            return;
        }

        $message = new PsrMessage(
            '{untiled} of {total} images are not tiled. Pre-tiling improves display performance significantly. Use the bulk tiler in the config form below.', // @translate
            ['untiled' => $untiled, 'total' => $totalImages]
        );
        if ($untiled > $totalImages / 2) {
            $messenger->addWarning($message);
        } else {
            $messenger->addSuccess($message);
        }

        // Warn about large untiled images.
        $settings = $services->get('Omeka\Settings');
        $maxSize = (int) $settings->get('imageserver_image_max_size', 5000000);
        $largeUntiled = (int) $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM media
            WHERE media_type LIKE 'image/%'
                AND media_type != 'image/svg+xml'
                AND size > ?
                AND JSON_EXTRACT(data, '$.tile') IS NULL
            SQL,
            [$maxSize]
        );
        if ($largeUntiled) {
            $hasVips = $this->isPhpVipsAvailable()
                || (bool) trim((string) shell_exec('which vips 2>/dev/null'));
            if ($hasVips) {
                $message = new PsrMessage(
                    '{count} large images (> {size} bytes) are not tiled. Vips handles them dynamically, but pre-tiling is recommended for best performance.', // @translate
                    ['count' => $largeUntiled, 'size' => $maxSize]
                );
                $messenger->addSuccess($message);
            } else {
                $message = new PsrMessage(
                    '{count} large images (> {size} bytes) are not tiled and vips is not available. These images cannot be processed dynamically. Pre-tile them or install vips.', // @translate
                    ['count' => $largeUntiled, 'size' => $maxSize]
                );
                $messenger->addError($message);
            }
        }
    }

    /**
     * Check if the fast tile script is set up in .htaccess.
     * If writable, add the rule automatically.
     */
    protected function checkFastTileScript(): void
    {
        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $scriptPath = __DIR__ . '/data/scripts/iiiftile.php';
        if (!file_exists($scriptPath)) {
            return;
        }

        $htaccessPath = OMEKA_PATH . '/.htaccess';
        $htaccess = @file_get_contents($htaccessPath);
        if ($htaccess === false) {
            return;
        }

        $marker = '# Module ImageServer: fast IIIF tile server.';

        // Determine the script path relative to the Omeka root.
        // Modules may be in modules/ or composer-addons/modules/.
        $modulePath = realpath(__DIR__);
        $omekaPath = realpath(OMEKA_PATH);
        $relativeScript = str_replace(
            $omekaPath . '/',
            '',
            $modulePath . '/data/scripts/iiiftile.php'
        );

        if (strpos($htaccess, 'iiiftile.php') !== false) {
            // Check that the path in .htaccess matches the current
            // module location (modules/ vs composer-addons/modules/).
            if (strpos($htaccess, $relativeScript) !== false) {
                $message = new PsrMessage(
                    'The fast IIIF tile script is active in .htaccess.' // @translate
                );
                $messenger->addSuccess($message);
            } else {
                $message = new PsrMessage(
                    'The fast IIIF tile script is in .htaccess but the path does not match the current module location. Update it to: {path}', // @translate
                    ['path' => $relativeScript]
                );
                $messenger->addError($message);
                // Auto-fix if writable.
                if (is_writable($htaccessPath)) {
                    $htaccess = preg_replace(
                        '#(RewriteRule\s+iiif/\(.*\)\s+)\S*iiiftile\.php#',
                        '$1' . $relativeScript,
                        $htaccess
                    );
                    file_put_contents($htaccessPath, $htaccess);
                    $message = new PsrMessage(
                        'The path has been updated automatically in .htaccess.' // @translate
                    );
                    $messenger->addSuccess($message);
                }
            }
            return;
        }

        $rule = "$marker\n"
            . "RewriteCond %{REQUEST_URI} /iiif/([23]/)?[^/]+/[^/]+/[^/]+/[^/]+/[^.]+\.\w+$\n"
            . "RewriteRule iiif/(.*) $relativeScript [END,E=IIIF_PATH:/iiif/\$1]\n";

        if (!is_writable($htaccessPath)) {
            $message = new PsrMessage(
                'For a better performance, it is recommended to redirect urls to iiif images to a specific quick script. However, the .htaccess file is not writeable. Add the following rules manually after "RewriteEngine On":{line_break}{rule}', // @translate
                ['line_break' => '<br>', 'rule' => '<pre>' . htmlspecialchars($rule) . '</pre>']
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
            return;
        }

        // Insert after "RewriteEngine On".
        $m = [];
        if (preg_match('/RewriteEngine\s+On\s*\n/i', $htaccess, $m, PREG_OFFSET_CAPTURE)) {
            $insertPos = $m[0][1] + strlen($m[0][0]);
            $htaccess = substr_replace($htaccess, "\n" . $rule . "\n", $insertPos, 0);
            file_put_contents($htaccessPath, $htaccess);
            $message = new PsrMessage(
                'The fast IIIF tile script has been added to .htaccess automatically.' // @translate
            );
            $messenger->addSuccess($message);
        } else {
            $message = new PsrMessage(
                'Could not find "RewriteEngine On" in .htaccess. Add the fast tile rules manually. See the module documentation.' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Remove the fast tile script rule from .htaccess on uninstall.
     */
    protected function removeFastTileHtaccess(): void
    {
        $htaccessPath = OMEKA_PATH . '/.htaccess';
        $htaccess = @file_get_contents($htaccessPath);
        if ($htaccess === false) {
            return;
        }

        $marker = '# Module ImageServer: fast IIIF tile server.';
        if (strpos($htaccess, $marker) === false) {
            return;
        }

        if (!is_writable($htaccessPath)) {
            $messenger = $this->getServiceLocator()
                ->get('ControllerPluginManager')->get('messenger');
            $messenger->addWarning(new PsrMessage(
                'The .htaccess file is not writable. Remove the fast tile rules (starting with "{marker}") manually.', // @translate
                ['marker' => $marker]
            ));
            return;
        }

        // Remove the marker line and the two following rewrite lines.
        $htaccess = preg_replace(
            '/' . preg_quote($marker, '/') . '\s*\n'
            . '(?:\s*RewriteCond\s+[^\n]*\n)?'
            . '(?:\s*RewriteRule\s+[^\n]*\n)?/',
            '',
            $htaccess
        );
        file_put_contents($htaccessPath, $htaccess);
    }

    /**
     * Check that required directories are writable.
     */
    protected function checkDirectories(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        $tileDir = $settings->get('imageserver_image_tile_dir', 'tile');
        $tilePath = $basePath . '/' . $tileDir;

        $dirs = [
            $tileDir => $tilePath,
            'tile/cache' => $basePath . '/' . $tileDir . '/cache',
        ];

        foreach ($dirs as $label => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            if (!is_dir($path) || !is_writable($path)) {
                $message = new PsrMessage(
                    'The directory "{dir}" is not writable. Check permissions.', // @translate
                    ['dir' => $label]
                );
                $messenger->addError($message);
            }
        }
    }

    /**
     * Check PHP memory limit for image processing.
     */
    protected function checkPhpMemory(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return;
        }

        $bytes = $this->parseMemoryValue($memoryLimit);
        $imager = $settings->get('imageserver_imager', 'Auto');

        // Vips uses memory-mapped files, so PHP memory limit is
        // less critical. For GD/Imagick, 256M is the minimum.
        $minBytes = ($imager === 'Vips') ? 128 * 1024 * 1024 : 256 * 1024 * 1024;
        if ($bytes < $minBytes) {
            $recommended = ($imager === 'Vips') ? '128M' : '256M';
            $message = new PsrMessage(
                'PHP memory_limit is {current}. For image processing, at least {recommended} is recommended.', // @translate
                ['current' => $memoryLimit, 'recommended' => $recommended]
            );
            $messenger->addWarning($message);
        }
    }

    protected function parseMemoryValue(string $value): int
    {
        $value = trim($value);
        $last = strtolower(substr($value, -1));
        $num = (int) $value;
        switch ($last) {
            case 'g':
                $num *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $num *= 1024 * 1024;
                break;
            case 'k':
                $num *= 1024;
                break;
        }
        return $num;
    }

    /**
     * Recommend IIIF v3 and level 2 support.
     */
    protected function checkIiifVersion(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $defaultVersion = $settings->get('iiifserver_media_api_default_version', '2');
        if ($defaultVersion !== '3') {
            $message = new PsrMessage(
                'The default IIIF image API version is {version}. Version 3 is recommended as the current standard, supported by all modern viewers.', // @translate
                ['version' => $defaultVersion ?: '0']
            );
            $messenger->addWarning($message);
        }

        $supported = $settings->get('iiifserver_media_api_supported_versions', []);
        if (!in_array('2/2', $supported) || !in_array('3/2', $supported)) {
            $message = new PsrMessage(
                'For full viewer compatibility, enable at least Image API 2 level 2 and Image API 3 level 2 in supported versions.' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Warn if no rights/license is configured in info.json.
     */
    protected function checkRights(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $rights = $settings->get('imageserver_info_rights', '');
        if (empty($rights) || $rights === 'none') {
            $message = new PsrMessage(
                'No rights/license is configured for the IIIF info.json. It is recommended to set a rights statement for IIIF compliance and interoperability.' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Verify that the base URL matches the current server URL.
     */
    protected function checkBaseUrl(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $stored = $settings->get('imageserver_base_url', '');
        if (empty($stored)) {
            return;
        }

        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $current = rtrim($urlHelper('top', [], ['force_canonical' => true]), '/') . '/';
        if ($stored !== $current) {
            $settings->set('imageserver_base_url', $current);
            $message = new PsrMessage(
                'The base URL was updated from "{old}" to "{new}".', // @translate
                ['old' => $stored, 'new' => $current]
            );
            $messenger->addSuccess($message);
        }
    }

    /**
     * Check if HTTP/2 is supported (important for parallel tile
     * loading).
     */
    protected function checkHttp2(): void
    {
        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        // Behind a reverse proxy, the protocol may be HTTP/1.1 even
        // if the client uses HTTP/2. Check the real protocol.
        $h2 = stripos($protocol, '2') !== false
            || !empty($_SERVER['HTTP2'])
            || !empty($_SERVER['H2']);
        if (!$h2) {
            $message = new PsrMessage(
                'The server does not appear to use HTTP/2. HTTP/2 allows parallel tile loading over a single connection, significantly improving viewer performance. Enable mod_http2 in Apache or equivalent in your web server.' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Check that PHP opcache is enabled.
     */
    protected function checkOpcache(): void
    {
        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')
            ->get('messenger');

        if (!function_exists('opcache_get_status')) {
            $message = new PsrMessage(
                'PHP opcache is not available. Enable it for better performance (opcache.enable=1 in php.ini).' // @translate
            );
            $messenger->addWarning($message);
            return;
        }

        $status = @opcache_get_status(false);
        if (!$status || empty($status['opcache_enabled'])) {
            $message = new PsrMessage(
                'PHP opcache is installed but not enabled. Enable it for better performance (opcache.enable=1 in php.ini).' // @translate
            );
            $messenger->addWarning($message);
        }
    }

    /**
     * Check if php-vips is actually usable (not just autoloaded).
     *
     * jcupitt/vips v1 requires ext-vips, v2 requires ext-ffi with
     * ffi.enable=true. The class may exist via Composer but fail at
     * runtime without the underlying extension.
     */
    protected function isPhpVipsAvailable(): bool
    {
        static $available;
        if ($available === null) {
            $available = false;
            if (class_exists('Jcupitt\Vips\Image')) {
                try {
                    \Jcupitt\Vips\Image::black(1, 1);
                    $available = true;
                } catch (\Throwable $e) {
                    // ext-vips or ext-ffi not available.
                }
            }
        }
        return $available;
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

        if ($imageSize === null) {
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
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('isPartial')) {
            return;
        }

        /** @var \Omeka\Entity\Item $item */
        $item = $event->getParam('response')->getContent();
        if (!count($item->getMedia())) {
            return;
        }

        $services = $this->getServiceLocator();
        $tileMode = $services->get('Omeka\Settings')
            ->get('imageserver_tile_mode', 'auto');
        $jobClass = $tileMode === 'auto'
            ? \ImageServer\Job\BulkSizerAndTiler::class
            : \ImageServer\Job\BulkSizer::class;

        if ($services->has('Common\DeferredJobDispatch')) {
            $services->get('Common\DeferredJobDispatch')->defer(
                $jobClass,
                'imageserver_size_tile',
                ['item_ids' => $item->getId()],
                [$this, 'mergeSizeTileParams']
            );
        } else {
            $services->get(\Omeka\Job\Dispatcher::class)->dispatch(
                $jobClass,
                [
                    'tasks' => ['size', 'tile'],
                    'query' => ['item_id' => [$item->getId()]],
                    'filter' => 'unsized',
                    'remove_destination' => 'skip',
                    'update_renderer' => false,
                ]
            );
        }
    }

    public function handleAfterSaveMedia(Event $event): void
    {
        // Don't run sizing during a batch edit of media, because it
        // runs one job by media and it is slow.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('isPartial')) {
            return;
        }

        /** @var \Omeka\Entity\Media $media */
        $media = $event->getParam('response')->getContent();

        if (strtok((string) $media->getMediaType(), '/') !== 'image'
            || $media->getIngester() === 'bulk_upload'
        ) {
            return;
        }

        $services = $this->getServiceLocator();

        // For standard media, defer sizing and tiling into a single
        // aggregated job, shared with handleAfterSaveItem. This
        // avoids launching one job per media.
        $tileMode = $services->get('Omeka\Settings')
            ->get('imageserver_tile_mode', 'auto');
        $jobClass = $tileMode === 'auto'
            ? \ImageServer\Job\BulkSizerAndTiler::class
            : \ImageServer\Job\BulkSizer::class;

        if ($services->has('Common\DeferredJobDispatch')) {
            $services->get('Common\DeferredJobDispatch')->defer(
                $jobClass,
                'imageserver_size_tile',
                ['media_ids' => $media->getId()],
                [$this, 'mergeSizeTileParams']
            );
        } else {
            $services->get(\Omeka\Job\Dispatcher::class)->dispatch(
                $jobClass,
                [
                    'tasks' => ['size', 'tile'],
                    'query' => ['id' => [$media->getId()]],
                    'filter' => 'unsized',
                    'remove_destination' => 'skip',
                    'update_renderer' => false,
                ]
            );
        }
    }

    /**
     * Merge callback for deferred size/tile jobs. Aggregates item and
     * media IDs from both handleAfterSaveItem and
     * handleAfterSaveMedia into a single job.
     */
    public function mergeSizeTileParams(
        string $key,
        array $allParams
    ): array {
        $mediaIds = [];
        $itemIds = [];
        foreach ($allParams as $p) {
            if (isset($p['media_ids'])) {
                $mediaIds[] = $p['media_ids'];
            }
            if (isset($p['item_ids'])) {
                $itemIds[] = $p['item_ids'];
            }
        }
        $query = [];
        if ($itemIds) {
            $query['item_id'] = array_unique($itemIds);
        }
        if ($mediaIds) {
            $query['id'] = array_unique($mediaIds);
        }
        return [
            'tasks' => ['size', 'tile'],
            'query' => $query,
            'filter' => 'unsized',
            'remove_destination' => 'skip',
            'update_renderer' => false,
        ];
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
