<?php declare(strict_types=1);
/**
 * Some variables should be manually set for now.
 *
 * @var string $defaultVersion
 * @var bool $versionAppend
 * @var string $prefix
 *
 * Unlike presentation api, the identifier must be url encoded in image api.
 * Nevertheless, when a prefix is set in the config, it can be bypassed to allow
 * the raw or the url-encoded identifier.
 * @link https://iiif.io/api/image/3.0/#9-uri-encoding-and-decoding
 */
namespace ImageServer;

// Write the default version ("2" or "3") here (and in iiif server if needed).
if (!isset($defaultVersion)) {
    $defaultVersion = '';
}
if (!isset($versionAppend)) {
    $versionAppend = false;
}
// If the version is set here, the route will skip it.
$version = $versionAppend ? '' : $defaultVersion;

// Write the prefix between the top of the iiif server and the identifier.
// This option allows to manage arks identifiers not url-encoded.
// Unlike presentation api, It is forbidden for image api, but some institutions
// need to bypass specifications.
// So prefix can be "ark:/12345/". Note that identifier part of the media is
// always encoded: "ark:/12345/b45r9z%2Ff15"
// So it runs like the base of the server is "iiif/3/ark:/12345/".
if (!isset($prefix)) {
    $prefix = '';
}
if ($prefix) {
    $urlEncodedPrefix = rawurlencode($prefix);
    $constraintPrefix = $prefix . '|' . $urlEncodedPrefix . '|' . str_replace('%3A', ':', $urlEncodedPrefix);
    $prefix = '[:prefix]';
} else {
    $constraintPrefix = '';
    $prefix = '';
}

return [
    'thumbnails' => [
        'thumbnailer_options' => [
            'vips_dir' => null,
        ],
    ],
    'file_renderers' => [
        // It is not simple to use a factory, because the core invokable overrides it.
        'invokables' => [
            'thumbnail' => Media\FileRenderer\ThumbnailRenderer::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            File\Thumbnailer\Vips::class => Service\File\Thumbnailer\VipsFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'formNote' => Form\View\Helper\FormNote::class,
        ],
        'factories' => [
            'tileInfo' => Service\ViewHelper\TileInfoFactory::class,
            'tileMediaInfo' => Service\ViewHelper\TileMediaInfoFactory::class,
        ],
        'delegators' => [
            'Laminas\Form\View\Helper\FormElement' => [
                Service\Delegator\FormElementDelegatorFactory::class,
            ],
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\Note::class => Form\Element\Note::class,
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
        ],
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\ImageController::class => Service\Controller\ImageControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'tileMediaInfo' => Mvc\Controller\Plugin\TileMediaInfo::class,
            'tileServer' => Mvc\Controller\Plugin\TileServer::class,
            'tileServerDeepZoom' => Mvc\Controller\Plugin\TileServerDeepZoom::class,
            'tileServerZoomify' => Mvc\Controller\Plugin\TileServerZoomify::class,
        ],
        'factories' => [
            'convertToImage' => Service\ControllerPlugin\ConvertToImageFactory::class,
            'imageServer' => Service\ControllerPlugin\ImageServerFactory::class,
            'sizer' => Service\ControllerPlugin\SizerFactory::class,
            'tileInfo' => Service\ControllerPlugin\TileInfoFactory::class,
            'tiler' => Service\ControllerPlugin\TilerFactory::class,
            'tileBuilder' => Service\ControllerPlugin\TileBuilderFactory::class,
            'tileRemover' => Service\ControllerPlugin\TileRemoverFactory::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'tile' => Service\Media\Ingester\TileFactory::class,
        ],
    ],
    'media_renderers' => [
        'factories' => [
            'tile' => Service\Media\Renderer\TileFactory::class,
        ],
    ],
    'router' => [
        // The routes override the ones set in the module Iiif Server in order
        // to redirect most of the urls directly to the image server.

        // In order to use clean urls, the identifier "id" can be any string without "/", not only Omeka id.
        // A specific config file is used is used to manage identifiers with "/", like arks.
        'routes' => [
            // The Api version 2 and 3 are supported via the optional "/version".
            // When version is not indicated in url, the default version is the one set in headers, else
            // via the setting "iiifserver_media_api_default_version".

            // @link http://iiif.io/api/image/2.0
            // @link http://iiif.io/api/image/3.0
            // Image          {scheme}://{server}{/prefix}/{identifier}

            'imageserver' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/iiif',
                    'defaults' => [
                        '__NAMESPACE__' => 'ImageServer\Controller',
                        'controller' => Controller\ImageController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    // The specification requires a 303 redirect to the info.json.
                    // Same as iiifserver/id, but needed to create urls.
                    'id' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => "[/:version]/$prefix:id",
                            'constraints' => [
                                'version' => '2|3',
                                'prefix' => $constraintPrefix,
                                // 'id' => '\d+',
                                'id' => '[^\/]+',
                            ],
                            'defaults' => [
                                'version' => $version,
                                'action' => 'id',
                            ],
                        ],
                    ],
                    // This route is a garbage collector that allows to return an error 400 or 501 to
                    // invalid or not implemented requests, as required by specification.
                    // This route should be set before the imageserver/media in order to be
                    // processed after it.
                    // TODO Simplify to any number of sub elements.
                    'media-bad' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => "[/:version]/$prefix:id/:region/:size/:rotation/:quality:.:format",
                            'constraints' => [
                                'version' => '2|3',
                                'prefix' => $constraintPrefix,
                                'id' => '[^\/]+',
                                'region' => '[^\/]+',
                                'size' => '[^\/]+',
                                'rotation' => '[^\/]+',
                                'quality' => '[^\/]+',
                                'format' => '[^\/]+',
                            ],
                            'defaults' => [
                                'version' => $version,
                                'action' => 'bad',
                            ],
                        ],
                    ],
                    'info' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => "[/:version]/$prefix:id/info.json",
                            'constraints' => [
                                'version' => '2|3',
                                'prefix' => $constraintPrefix,
                                'id' => '[^\/]+',
                            ],
                            'defaults' => [
                                'version' => $version,
                                'action' => 'info',
                            ],
                        ],
                    ],
                    // Warning: the format is separated with a ".", not a "/".
                    // The specification is the same for versions 2.0 and 3.0, except for size,
                    // where 3.0 rejects "full", but allows upscaling with "^". Furthermore, the
                    // pct number should be lower than 100, except for upscaling.
                    // Only one route is used for simplicity. The controller checks differences
                    // between version 2 and 3 and may return error, or return the image with
                    // the canonical url.
                    'media' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => "[/:version]/$prefix:id/:region/:size/:rotation/:quality:.:format",
                            'constraints' => [
                                'version' => '2|3',
                                'prefix' => $constraintPrefix,
                                'id' => '[^\/]+',
                                'region' => 'full|square|\d+,\d+,\d+,\d+|pct:\d+\.?\d*,\d+\.?\d*,\d+\.?\d*,\d+\.?\d*',
                                // Version 2.0 allows "max", but version 3.0 rejects "full".
                                // Version 3.0 adds upscalling: "^max", "^w,", "^,h", "^pct:n"; "^w,h", and "^!w,h".
                                // Old version 2.1.
                                // 'size' => 'full|\d+,\d*|\d*,\d+|pct:\d+\.?\d*|!\d+,\d+',
                                // New multi-version.
                                'size' => 'full|(?:%5E|%5e|\^)?(?:max|\d+,|,\d+|pct:\d+\.?\d*|!?\d+,\d+)',
                                'rotation' => '\!?(?:(?:[0-2]?[0-9]?[0-9]|3[0-5][0-9])(?:\.\d*)?|360)',
                                'quality' => 'default|color|gray|bitonal',
                                // May requires additional packages. Checked in controller.
                                'format' => 'jpg|png|gif|webp|tif|jp2|pdf',
                            ],
                            'defaults' => [
                                'version' => $version,
                                'action' => 'fetch',
                            ],
                        ],
                    ],

                    // Special route for the canvas placeholder in manifest v2,
                    // that is used in particular when there is no image server
                    // or when the media is private.
                    // @see \IiifServer\View\Helper\IiifManifest2::_iiifCanvasPlaceholder()
                    'placeholder' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '[/:version]/ixif-message-0/res/placeholder',
                            'constraints' => [
                                'version' => '2|3',
                            ],
                            'defaults' => [
                                'version' => $version,
                                'action' => 'placeholder',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'archiverepertory' => [
        'ingesters' => [
            'tile' => [
                // This is the param "imageserver_image_tile_dir".
                'path' => 'tile',
                'extension' => [
                    '.dzi',
                    '.js',
                    // The classes are not available before the end of install.
                    // TileInfo::FOLDER_EXTENSION_DEEPZOOM,
                    '_files',
                    // TileInfo::FOLDER_EXTENSION_ZOOMIFY,
                    '_zdata',
                    // Jpeg 2000 files.
                    '.jp2',
                    // Tiled pyramidal tiff.
                    '.tif',
                ],
            ],
        ],
    ],
    'csv_import' => [
        'media_ingester_adapter' => [
            'tile' => MediaIngesterAdapter\TileMediaIngesterAdapter::class,
        ],
    ],
    'imageserver' => [
        'config' => [
            // Use the same name than the module Iiif Server for simplicity.
            // Except "iiifserver_media_api_url", that is hidden.
            // Hidden key.
            'iiifserver_media_api_url' => '',
            'iiifserver_media_api_default_version' => '2',
            'iiifserver_media_api_supported_versions' => [
                '2/2',
                '3/2',
            ],
            // The version and the prefix should be set in module config routing for now.
            'iiifserver_media_api_version_append' => false,
            'iiifserver_media_api_prefix' => '',
            'iiifserver_media_api_identifier' => 'media_id',
            // Hidden option.
            'iiifserver_media_api_default_supported_version' => [
                'service' => '2',
                'level' => '2',
            ],
            // Content of the info.json.
            'imageserver_info_rights' => 'property_or_url',
            'imageserver_info_rights_property' => 'dcterms:license',
            'imageserver_info_rights_uri' => 'https://rightsstatements.org/vocab/CNE/1.0/',
            'imageserver_info_rights_url' => '',
            'imageserver_info_rights_text' => '',
            'imageserver_auto_tile' => false,
            'imageserver_imager' => 'Auto',
            'imageserver_image_max_size' => 10000000,
            'imageserver_image_tile_type' => 'deepzoom',
            // Internal option to get Omeka url in background process.
            'imageserver_base_url' => '',
            // This param may be changed locally.
            // If updated, the path for the ArchiveRepertory ingester should be changed.
            'imageserver_image_tile_dir' => 'tile',
        ],
        'settings' => [
            'imageserver_default_thumbnail_type' => 'tile',
            'imageserver_tile_fallback' => 'tile_large',
        ],
        'site_settings' => [
            'imageserver_default_thumbnail_type' => 'large',
            'imageserver_tile_fallback' => 'tile_large',
        ],
    ],
];
