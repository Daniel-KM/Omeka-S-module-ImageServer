<?php declare(strict_types=1);

namespace ImageServer\Form;

use Common\Form\Element as CommonElement;
use ImageServer\ImageServer\ImageServer;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    /**
     * @var array
     */
    protected $imagers = [];

    /**
     * @var bool
     */
    protected $supportJpeg2000;

    /**
     * @var bool
     */
    protected $supportTiledTiff;

    protected $elementGroups = [
        'infra' => 'Infrastructure', // @translate
        'tiling' => 'Tiling', // @translate
        'metadata' => 'Metadata and rights', // @translate
        'bulk' => 'Bulk processing', // @translate
    ];

    public function init(): void
    {
        $this
            ->setOption('element_groups', $this->elementGroups)

            // Use the same name than the module Iiif Server for simplicity.
            // Except "iiifserver_media_api_url", that is hidden.

            ->add([
                'name' => 'iiifserver_media_api_url',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'value' => '',
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_default_version',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'infra',
                    'label' => 'Default IIIF image api version', // @translate
                    'info' => 'Set the version of the iiif info.json to provide. The image server should support it.', // @translate
                    'value_options' => [
                        '0' => 'No image server', // @translate
                        '1' => 'Image Api 1', // @translate
                        '2' => 'Image Api 2', // @translate
                        '3' => 'Image Api 3', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_default_version',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_supported_versions',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'infra',
                    'label' => 'Supported IIIF image api versions and max compliance level', // @translate
                    'value_options' => [
                        'v1' => [
                            'label' => 'Image API 1',
                            'options' => [
                                '1/0' => 'Level 0', // @translate
                                '1/1' => 'Level 1', // @translate
                                '1/2' => 'Level 2', // @translate
                            ],
                        ],
                        'v2' => [
                            'label' => 'Image API 2',
                            'options' => [
                                '2/0' => 'Level 0', // @translate
                                '2/1' => 'Level 1', // @translate
                                '2/2' => 'Level 2', // @translate
                            ],
                        ],
                        'v3' => [
                            'label' => 'Image API 3',
                            'options' => [
                                '3/0' => 'Level 0', // @translate
                                '3/1' => 'Level 1', // @translate
                                '3/2' => 'Level 2', // @translate
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_supported_versions',
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_version_append',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'infra',
                    'label' => 'Append the version to the url (to be set inside module.config.php currently)', // @translate
                    'info' => 'If set, the version will be appended to the url of the server: "iiif/3".', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_version_append',
                ],
            ])

            /*
            ->add([
                'name' => 'iiifserver_media_api_prefix',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Append a prefix to the url (to be set inside module.config.php currently)', // @ translate
                    'info' => 'If set, the prefix will be added after the version: "iiif/3/xxx".', // @ translate
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_prefix',
                ],
            ])
            */

            ->add([
                'name' => 'iiifserver_media_api_identifier',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'infra',
                    'label' => 'Media identifier', // @translate
                    'info' => 'Using the full filename with extension for images allows to use an image server like Cantaloupe sharing the Omeka original files directory. In other cases, this option is not recommended because the identifier should not have an extension.', // @translate
                    'value_options' => [
                        'default' => 'Default', // @translate
                        'media_id' => 'Media id', // @translate
                        'storage_id' => 'Filename', // @translate
                        'filename' => 'Filename with extension (all)', // @translate
                        'filename_image' => 'Filename with extension (image only)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_identifier',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'imageserver_tile_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'tiling',
                    'label' => 'Tile processing mode', // @translate
                    'info' => 'If set manual, to run the task below will be required to create tiles. Unless in the case of an external server, it is recommended to set automatic tiling once all existing items are tiled to avoid to overload the server. So bulk tile all items first below.', // @translate
                    'value_options' => [
                        'auto' => 'Create tiles automatically on save (recommended without external server)', // @translate
                        'manual' => 'Create tiles manually', // @translate
                        'external' => 'Use an external image server', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_tile_mode',
                ],
            ])
            ->add([
                'name' => 'imageserver_image_tile_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'tiling',
                    'label' => 'Tiling type', // @translate
                    'info' => <<<'TXT'
                        If vips is available, the recommended processor strategy is "Tiled tiff". If jpeg2000 is available, use "Jpeg 2000". Else, use Deepzoom or Zoomify.
                        Deep Zoom Image is a free proprietary format from Microsoft largely supported.
                        Zoomify is an old format that was largely supported by proprietary softwares and free viewers.
                        All formats are served as native by default, but may be served as IIIF too when a viewer request it.
                        TXT, // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer#image-server',
                    'value_options' => [
                        'deepzoom' => [
                            'value' => 'deepzoom',
                            'label' => 'Deep Zoom Image', // @translate
                        ],
                        'zoomify' => [
                            'value' => 'zoomify',
                            'label' => 'Zoomify', // @translate
                        ],
                        'jpeg2000' => [
                            'value' => 'jpeg2000',
                            'label' => $this->supportJpeg2000
                                ? 'Jpeg 2000' // @translate
                                : 'Jpeg 2000 (not supported)', // @translate
                            'disabled' => !$this->supportJpeg2000,
                        ],
                        'tiled_tiff' => [
                            'value' => 'tiled_tiff',
                            'label' => $this->supportTiledTiff
                                ? 'Tiled tiff' // @translate
                                : 'Tiled tiff (not supported)', // @translate
                            'disabled' => !$this->supportTiledTiff,
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver-image-tile-type',
                ],
            ])
            ->add([
                'name' => 'imageserver_imager',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'element_group' => 'tiling',
                    'label' => 'Image processor', // @translate
                    'info' => <<<'TXT'
                        Vips is the quickest in all cases, then GD is a little faster than ImageMagick, but ImageMagick manages more formats.
                        Nevertheless, the performance depends on your installation and your server.
                        TXT, // @translate
                    'value_options' => $this->getImagers(),
                ],
                'attributes' => [
                    'id' => 'imageserver_imager',
                ],
            ])
            ->add([
                // Limits for all versions.
                'name' => 'imageserver_image_max_size',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'tiling',
                    'label' => 'Max dynamic size for images', // @translate
                    'info' => <<<'TXT'
                        Set the maximum size in bytes for the dynamic processing of images.
                        Beyond this limit, the plugin will require a tiled image.
                        Let empty to allow processing of any image.
                        With vips, this option is bypassed.
                        TXT, // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver-image-max-size',
                ],
            ])

            ->add([
                'name' => 'imageserver_info_rights',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'element_group' => 'metadata',
                    'label' => 'Rights source', // @translate
                    'info' => 'For IIIF v3, the value must be a URL from creativecommons.org or rightsstatements.org.', // @translate
                    'value_options' => [
                        'none' => 'No mention of rights', // @translate
                        'source_fallback' => [
                            'label' => 'Source with fallback', // @translate
                            'options' => [
                                'item_or_url' => 'Item property, or default url below', // @translate
                                'property_or_url' => 'Media property, or default url', // @translate
                                'property_or_text' => 'Media property, or default text (IIIF v2 only)', // @translate
                            ],
                        ],
                        'source_none' => [
                            'label' => 'Source without fallback', // @translate
                            'options' => [
                                'item' => 'Item property (from IIIF Server config)', // @translate
                                'property' => 'Media property below', // @translate
                                'url' => 'Default url below', // @translate
                                'text' => 'Default text below (IIIF v2 only)', // @translate
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights',
                    'class' => 'chosen-select',
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_property',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'element_group' => 'metadata',
                    'label' => 'Rights property', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_property',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a media property…', // @translate
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_uri',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'element_group' => 'metadata',
                    'label' => 'Default rights URL', // @translate
                    'value_options' => [
                        '' => 'Uri below', // @translate
                        // CreativeCommons.
                        'creative-commons-0' => [
                            'label' => 'Creative Commons 0', // @translate
                            'options' => [
                                'https://creativecommons.org/publicdomain/zero/1.0/' => 'Creative Commons CC0 Universal Public Domain Dedication', // @translate
                            ],
                        ],
                        // v3 international
                        'creative-commons-3' => [
                            'label' => 'Creative Commons 3.0 International', // @translate
                            'options' => [
                                'https://creativecommons.org/licenses/by/3.0/' => 'Creative Commons Attribution 3.0 International', // @translate
                                'https://creativecommons.org/licenses/by-sa/3.0/' => 'Creative Commons Attribution-ShareAlike 3.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc/3.0' => 'Creative Commons Attribution-NonCommercial 3.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc-sa/3.0' => 'Creative Commons Attribution-NonCommercial-ShareAlike 3.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc-nd/3.0' => 'Creative Commons Attribution-NonCommercial-NoDerivatives 3.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nd/3.0' => 'Creative Commons Attribution-NoDerivatives 3.0 International', // @translate
                            ],
                        ],
                        // v4 international
                        'creative-commons-4' => [
                            'label' => 'Creative Commons 4.0 International', // @translate
                            'options' => [
                                'https://creativecommons.org/licenses/by/4.0/' => 'Creative Commons Attribution 4.0 International', // @translate
                                'https://creativecommons.org/licenses/by-sa/4.0/' => 'Creative Commons Attribution-ShareAlike 4.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc/4.0' => 'Creative Commons Attribution-NonCommercial 4.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc-sa/4.0' => 'Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nc-nd/4.0' => 'Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International', // @translate
                                'https://creativecommons.org/licenses/by-nd/4.0' => 'Creative Commons Attribution-NoDerivatives 4.0 International', // @translate
                            ],
                        ],
                        // RightsStatements.
                        'rights-statements' => [
                            'label' => 'Rights Statements', // @translate
                            'options' => [
                                'https://rightsstatements.org/vocab/InC/1.0/' => 'In Copyright', // @translate
                                'https://rightsstatements.org/vocab/InC-RUU/1.0/' => 'In Copyright - Rights-holder(s) Unlocatable or Unidentifiable', // @translate
                                'https://rightsstatements.org/vocab/InC-NC/1.0/' => 'In Copyright - Non-Commercial Use Permitted', // @translate
                                'https://rightsstatements.org/vocab/InC-EDU/1.0/' => 'In Copyright - Educational Use Permitted', // @translate
                                'https://rightsstatements.org/vocab/InC-OW-EU/1.0/' => 'In Copyright - EU Orphan Work', // @translate
                                'https://rightsstatements.org/vocab/NoC-OKLR/1.0/' => 'No Copyright - Other Known Legal Restrictions', // @translate
                                'https://rightsstatements.org/vocab/NoC-CR/1.0/' => 'No Copyright - Contractual Restrictions', // @translate
                                'https://rightsstatements.org/vocab/NoC-NC/1.0/' => 'No Copyright - Non-Commercial Use Only', // @translate
                                'https://rightsstatements.org/vocab/NoC-US/1.0/' => 'No Copyright - United States', // @translate
                                'https://rightsstatements.org/vocab/NKC/1.0/' => 'No Known Copyright', // @translate
                                'https://rightsstatements.org/vocab/UND/1.0/' => 'Copyright Undetermined', // @translate
                                'https://rightsstatements.org/vocab/CNE/1.0/' => 'Copyright Not Evaluated', // @translate
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_uri',
                    'class' => 'chosen-select',
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_url',
                'type' => Element\Url::class,
                'options' => [
                    'element_group' => 'metadata',
                    'label' => 'Custom rights URL (if not selected above)', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_url',
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_text',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'metadata',
                    'label' => 'Default rights text (IIIF v2 only)', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_text',
                ],
            ])

        ;

        // Bulk processing.
        $this
            ->add([
                'name' => 'imageserver_bulk_prepare',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Bulk processing', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_bulk_prepare',
                ],
            ]);
        $bulkFieldset = $this->get('imageserver_bulk_prepare');
        $bulkFieldset
            ->add([
                'name' => 'note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => 'Run tiling and/or dimension sizing for existing images via a background job. Dimensions are needed for the IIIF info.json. Tiles improve zoom performance in viewers.', // @translate
                ],
            ])
            ->add([
                'name' => 'tasks',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Tasks to run', // @translate
                    'value_options' => [
                        'size' => 'Compute dimensions (sizing)', // @translate
                        'tile' => 'Create tiles (tiling)', // @translate
                        'tile_clean' => 'Remove all tiles and tile metadata', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'tasks',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Filter items (optional)', // @translate
                    'info' => 'Restrict processing to specific items. Leave empty to process all items.', // @translate
                    'query_resource_type' => 'items',
                    'query_partial_excludelist' => [
                        'common/advanced-search/sort',
                    ],
                ],
                'attributes' => [
                    'id' => 'tiler_query',
                ],
            ])
            ->add([
                'name' => 'filter_sized',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Sizing scope', // @translate
                    'info' => 'Choose which images to size.', // @translate
                    'value_options' => [
                        'unsized' => 'Only images without dimensions', // @translate
                        'all' => 'All images', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'filter_sized',
                    'value' => 'unsized',
                ],
            ])
            ->add([
                'name' => 'remove_destination',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Tiling scope', // @translate
                    'info' => 'Choose how to handle existing tiles when creating new ones.', // @translate
                    'value_options' => [
                        'skip' => 'Only images without tiles', // @translate
                        'specific' => 'Only images with tiles', // @translate
                        'all' => 'All images', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'remove_destination',
                    'value' => 'skip',
                ],
            ])
            ->add([
                'name' => 'process',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Run in background', // @translate
                ],
                'attributes' => [
                    'id' => 'process',
                    'value' => 'Process', // @translate
                ],
            ])
        ;

    }

    /**
     * @param array $imagers
     * @return self
     */
    public function setImagers(array $imagers): self
    {
        $this->imagers = $imagers;
        return $this;
    }

    /**
     * @return array
     */
    public function getImagers(): array
    {
        return $this->imagers;
    }

    /**
     * @return self
     */
    public function setImageServer(ImageServer $imageServer): self
    {
        $this->supportJpeg2000 = $imageServer->getImager()->checkMediaType('image/jp2');
        $this->supportTiledTiff = $imageServer->getImager()->checkMediaType('image/tiff');
        return $this;
    }
}
