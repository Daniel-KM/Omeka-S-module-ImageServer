<?php declare(strict_types=1);
namespace ImageServer;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $oldVersion
 * @var string $newVersion
 */
$services = $serviceLocator;

/**
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Omeka\Api\Manager $api
 * @var array $config
 */
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$api = $services->get('Omeka\ApiManager');
$config = require dirname(__DIR__, 2) . '/config/module.config.php';
$defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
$settings = $services->get('Omeka\Settings');

if (version_compare($oldVersion, '3.6.2', '<')) {
    $settings->set('imageserver_info_default_version', $settings->get('imageserver_manifest_version'));
    $settings->delete('imageserver_manifest_version');
    $settings->set('imageserver_info_version_append', false);
}

if (version_compare($oldVersion, '3.6.3.3', '<')) {
    $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
    if ($module && version_compare($module->getIni('version'), '3.3.26', '<')) {
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
            'Generic', '3.3.26'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }
}
