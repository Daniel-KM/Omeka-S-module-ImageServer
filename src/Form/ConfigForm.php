<?php declare(strict_types=1);

namespace ImageServer\Form;

use IiifServer\Form\Element\OptionalUrl;
use ImageServer\Form\Element\Note;
use ImageServer\ImageServer\ImageServer;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\I18n\Translator\TranslatorAwareTrait;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

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

    public function init(): void
    {
        // Use the same name than the module Iiif Server for simplicity.
        // Except "iiifserver_media_api_url", that is hidden.

        $this
            ->add([
                'name' => 'fieldset_media_api',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'label' => 'Image server', // @translate
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_url',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'value' => '',
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_default_version',
                'type' => Element\Radio::class,
                'options' => [
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
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Supported IIIF image api versions and max compliance level', // @translate
                    'value_options' => [
                        '1/0' => 'Image Api 1 level 0', // @translate
                        '1/1' => 'Image Api 1 level 1', // @translate
                        '1/2' => 'Image Api 1 level 2', // @translate
                        '2/0' => 'Image Api 2 level 0', // @translate
                        '2/1' => 'Image Api 2 level 1', // @translate
                        '2/2' => 'Image Api 2 level 2', // @translate
                        '3/0' => 'Image Api 3 level 0', // @translate
                        '3/1' => 'Image Api 3 level 1', // @translate
                        '3/2' => 'Image Api 3 level 2', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_supported_versions',
                ],
            ])

            ->add([
                'name' => 'iiifserver_media_api_version_append',
                'type' => Element\Checkbox::class,
                'options' => [
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
                    'label' => 'Append a prefix to the url (to be set inside module.config.php currently)', // @translate
                    'info' => 'If set, the prefix will be added after the version: "iiif/3/xxx".', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_prefix',
                ],
            ])
            */

            ->add([
                'name' => 'iiifserver_media_api_identifier',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Media identifier', // @translate
                    'info' => 'Using the full filename allows to use an image server like Cantaloupe sharing the Omeka original files directory.', // @translate
                    'value_options' => [
                        'default' => 'Default', // @translate
                        'media_id' => 'Media id', // @translate
                        'storage_id' => 'Filename', // @translate
                        'filename' => 'Filename with extension', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifserver_media_api_identifier',
                    'required' =>  true,
                ],
            ])

            ->add([
                'name' => 'fieldset_media_infojson',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'label' => 'Content of media info.json', // @translate
                ],
            ])

            ->add([
                'name' => 'imageserver_info_rights',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Rights (license)', // @translate
                    'value_options' => [
                        'none' => 'No mention', // @translate
                        'text' => 'Specified text below (only for iiif 2.0)', // @translate
                        'url' => 'Specified license url below', // @translate
                        'property' => 'Specified property below', // @translate
                        'property_or_text' => 'Property if any, else specified license text (only for iiif 2.0)', // @translate
                        'property_or_url' => 'Property if any, else specified license', // @translate
                        'item' => 'Url specified by the iiif server for the item', // @translate
                        'item_or_url' => 'Item rights url if any, else specified license', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights',
                    'class' => 'chosen-select',
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_property',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Property to use for rights (license)', // @translate
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
                'name' => 'imageserver_info_rights_url',
                'type' => Element\Url::class,
                'options' => [
                    'label' => 'Url of the license of the media', // @translate
                    'info' => 'The license for the media must be an url from https://creativecommons.org or https://rightsstatements.org.', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_url',
                ],
            ])
            ->add([
                'name' => 'imageserver_info_rights_text',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Default license text (only for iiif 2.0)', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_info_rights_text',
                ],
            ])

            ->add([
                'name' => 'imageserver_tiling',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Tiling service', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_tiling',
                ],
            ])
            ->add([
                'name' => 'imageserver_auto_tile',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Tile images automatically when saved (important: enable it only when all existing images are already tiled)', // @translate
                    'info' => 'If set, any action on items will create tiles if they are not present, so it can overload the server. So bulk tile all items first below.', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_auto_tile',
                ],
            ])
            ->add([
                'name' => 'imageserver_imager',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Image processor', // @translate
                    'info' => $this->translate('Vips is the quickest in all cases, then GD is a little faster than ImageMagick, but ImageMagick manages more formats.') // @translate
                        . ' ' . $this->translate('Nevertheless, the performance depends on your installation and your server.'), // @translate
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
                    'label' => 'Max dynamic size for images', // @translate
                    'info' => $this->translate('Set the maximum size in bytes for the dynamic processing of images.') // @translate
                        . ' ' . $this->translate('Beyond this limit, the plugin will require a tiled image.') // @translate
                        . ' ' . $this->translate('Let empty to allow processing of any image.') // @translate
                        . ' ' . $this->translate('With vips, this option is bypassed.'), // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver-image-max-size',
                ],
            ])
            ->add([
                'name' => 'imageserver_image_tile_type',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tiling type', // @translate
                    'info' => $this->translate('If vips is available, the recommended processor strategy is "Tiled tiff". If jpeg2000 is available, use "Jpeg 2000". Else, use Deepzoom or Zoomify.') // @translate
                        . ' ' . $this->translate('Deep Zoom Image is a free proprietary format from Microsoft largely supported.') // @translate
                        . ' ' . $this->translate('Zoomify is an old format that was largely supported by proprietary softwares and free viewers.') // @translate
                        . ' ' . $this->translate('All formats are served as native by default, but may be served as IIIF too when a viewer request it.'), // @translate
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
                'name' => 'imageserver_bulk_prepare',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Bulk prepare tiles and sizes', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_bulk_prepare',
                ],
            ])
        ;

        $bulkFieldset = $this->get('imageserver_bulk_prepare');
        $bulkFieldset
            ->add([
                'name' => 'note',
                'type' => Note::class,
                'options' => [
                    'text' => 'This process builds tiles and and saves dimensions of existing files via a background job.
To save the height and the width of all images and derivatives allows to speed up creation of the iiif "info.json" of medias.', // @translate
                ],
            ])
            ->add([
                'name' => 'query',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Query', // @translate
                    'info' => 'This query will be used to select all items whose attached images will be prepared in the background.', // @translate
                    'documentation' => 'https://omeka.org/s/docs/user-manual/sites/site_pages/#browse-preview',
                ],
                'attributes' => [
                    'id' => 'tiler_query',
                ],
            ])
            ->add([
                'name' => 'tasks',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    'value_options' => [
                        'tile' => 'Tiling', // @translate
                        'size' => 'Sizing', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'tasks',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'remove_destination',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Limit process to prepare tiles', // @translate
                    'value_options' => [
                        'skip' => 'Keep existing', // @translate
                        'specific' => 'Remove existing tiles for the specified format', // @translate
                        'all' => 'Remove all existing tiles', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'remove_destination',
                    'value' => 'skip',
                ],
            ])
            ->add([
                'name' => 'update_renderer',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Renderer', // @translate
                    'value_options' => [
                        '0' => 'Keep existing', // @translate
                        'file' => 'File', // @translate
                        'tile' => 'Tile', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'update_renderer',
                    'value' => '0',
                ],
            ])
            ->add([
                'name' => 'filter_sized',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Limit process to get sizes', // @translate
                    'value_options' => [
                        'unsized' => 'Keep existing', // @translate
                        'sized' => 'Only already sized', // @translate
                        'all' => 'All', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'filter_sized',
                    'value' => 'unsized',
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

        $this->getInputFilter()
            ->add([
                'name' => 'imageserver_info_rights',
                'required' => false,
            ])
            ->add([
                'name' => 'imageserver_info_rights_url',
                'required' => false,
            ])
            ->add([
                'name' => 'imageserver_info_rights_property',
                'required' => false,
            ])
            ->add([
                'name' => 'imageserver_imager',
                'required' => false,
            ])
            ->add([
                'name' => 'imageserver_image_tile_type',
                'required' => false,
            ])
            ->add([
                'name' => 'imageserver_bulk_prepare',
                'required' => false,
            ])
            ->add([
                'name' => 'tasks',
                'required' => false,
            ])
            ->add([
                'name' => 'remove_destination',
                'required' => false,
            ])
            ->add([
                'name' => 'update_renderer',
                'required' => false,
            ])
            ->add([
                'name' => 'filter_sized',
                'required' => false,
            ])
        ;
    }

    protected function translate($args): string
    {
        $translator = $this->getTranslator();
        return $translator->translate($args);
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
