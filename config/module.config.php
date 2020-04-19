<?php
namespace ImageServer;

return [
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
            'iiifInfo' => View\Helper\IiifInfo::class,
        ],
        'factories' => [
            'iiifInfo2' => Service\ViewHelper\IiifInfo2Factory::class,
            'iiifInfo3' => Service\ViewHelper\IiifInfo3Factory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\ImageController::class => Service\Controller\ImageControllerFactory::class,
            Controller\MediaController::class => Service\Controller\MediaControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'iiifImageJsonLd' => Mvc\Controller\Plugin\IiifImageJsonLd::class,
            'tileBuilder' => Mvc\Controller\Plugin\TileBuilder::class,
            'tileInfo' => Mvc\Controller\Plugin\TileInfo::class,
            'tileServer' => Mvc\Controller\Plugin\TileServer::class,
        ],
        'factories' => [
            'imageSize' => Service\ControllerPlugin\ImageSizeFactory::class,
            'tiler' => Service\ControllerPlugin\TilerFactory::class,
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
        'routes' => [
            // @todo It is recommended to use a true identifier (ark, urn…], not an internal id.

            // @link http://iiif.io/api/image/2.0
            // @link http://iiif.io/api/image/3.0
            // Image          {scheme}://{server}{/prefix}/{identifier}

            'imageserver' => [
                'type' => \Zend\Router\Http\Literal::class,
                'options' => [
                    'route' => '/iiif-img',
                    'defaults' => [
                        '__API__' => true,
                        '__NAMESPACE__' => 'ImageServer\Controller',
                        'controller' => Controller\ImageController::class,
                        'action' => 'index',
                    ],
                ],
                // This should be false, but we need the default url.
                'may_terminate' => true,
                'child_routes' => [
                    // The specification requires a 303 redirect to the info.json.
                    'id' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                        ],
                    ],
                    'info' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id/info.json',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'info',
                            ],
                        ],
                    ],
                    // This route is a garbage collector that allows to return an error 400 or 501 to
                    // invalid or not implemented requests, as required by specification.
                    // This route should be set before the imageserver/media in order to be
                    // processed after it.
                    // TODO Simplify to any number of sub elements.
                    'media-bad' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id/:region/:size/:rotation/:quality:.:format',
                            'constraints' => [
                                'id' => '\d+',
                                'region' => '.+',
                                'size' => '.+',
                                'rotation' => '.+',
                                'quality' => '.+',
                                'format' => '.+',
                            ],
                            'defaults' => [
                                'action' => 'bad',
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
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id/:region/:size/:rotation/:quality:.:format',
                            'constraints' => [
                                'id' => '\d+',
                                'region' => 'full|\d+,\d+,\d+,\d+|pct:\d+\.?\d*,\d+\.?\d*,\d+\.?\d*,\d+\.?\d*',
                                // Version 2.0 allows "max", but version 3.0 rejects "full".
                                // Version 3.0 adds upscalling: "^max", "^w,", "^,h", "^pct:n"; "^w,h", and "^!w,h".
                                // Old version 2.1.
                                // 'size' => 'full|\d+,\d*|\d*,\d+|pct:\d+\.?\d*|!\d+,\d+',
                                // New multi-version.
                                'size' => 'full|\^?max|\^?\d+,|\^?,\d+|\^?pct:\d+\.?\d*|\^?!?\d+,\d+',
                                'rotation' => '\!?(?:(?:[0-2]?[0-9]?[0-9]|3[0-5][0-9])(?:\.\d*)?|360)',
                                'quality' => 'default|color|gray|bitonal',
                                // May requires additional packages. Checked in controller.
                                'format' => 'jpg|png|gif|webp|tif|jp2|pdf',
                            ],
                            'defaults' => [
                                'action' => 'fetch',
                            ],
                        ],
                    ],
                ],
            ],

            'mediaserver' => [
                'type' => \Zend\Router\Http\Literal::class,
                'options' => [
                    'route' => '/ixif-media',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'ImageServer\Controller',
                        'controller' => Controller\MediaController::class,
                        'action' => 'index',
                    ],
                ],
                // This should be false, but we need the default url.
                'may_terminate' => true,
                'child_routes' => [
                    // A redirect to the info.json is required by the specification.
                    'id' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__API__' => true,
                            ],
                        ],
                    ],
                    'info' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id/info.json',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__API__' => true,
                                'action' => 'info',
                            ],
                        ],
                    ],
                    // This route is a garbage collector that allows to return an error 400 or 501 to
                    // invalid or not implemented requests, as required by specification.
                    // This route should be set before the mediaserver/media in order to be
                    // processed after it.
                    'media-bad' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id:.:format',
                            'constraints' => [
                                'id' => '\d+',
                                'format' => '.+',
                            ],
                            'defaults' => [
                                'action' => 'bad',
                            ],
                        ],
                    ],
                    // Warning: the format is separated with a ".", not a "/".
                    // TODO pdf is not an audio video media.
                    'media' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:id:.:format',
                            'constraints' => [
                                'id' => '\d+',
                                'format' => 'pdf|mp3|ogg|mp4|webm|ogv',
                            ],
                            'defaults' => [
                                'action' => 'fetch',
                            ],
                        ],
                    ],
                ],
            ],

            // For IxIF, some json files should be available to describe media for context.
            // This is not used currently: the Wellcome uris are kept because they are set
            // for main purposes in ImageServer.
            // @link https://gist.github.com/tomcrane/7f86ac08d3b009c8af7c
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
                'path' => 'tile',
                'extension' => [
                    '.dzi',
                    '.js',
                    // The classes are not available before the end of install.
                    // TileInfo::FOLDER_EXTENSION_DEEPZOOM,
                    '_files',
                    // TileInfo::FOLDER_EXTENSION_ZOOMIFY,
                    '_zdata',
                ],
            ],
        ],
    ],
    'imageserver' => [
        'config' => [
            'imageserver_info_version' => '2',
            'imageserver_info_rights' => 'property_or_url',
            'imageserver_info_rights_property' => 'dcterms:license',
            'imageserver_info_rights_url' => '',
            'imageserver_image_creator' => 'Auto',
            'imageserver_image_max_size' => 10000000,
            'imageserver_image_tile_dir' => 'tile',
            'imageserver_image_tile_type' => 'deepzoom',
        ],
    ],
];
