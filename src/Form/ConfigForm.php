<?php declare(strict_types=1);
namespace ImageServer\Form;

use ImageServer\Form\Element\Note;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\I18n\Translator\TranslatorAwareTrait;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    public function init(): void
    {
        $this
            ->add([
                'name' => 'imageserver_info_default_version',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Default IIIF api version (info)', // @translate
                    'info' => 'Set the version of the manifest to provide.', // @translate
                    'value_options' => [
                        '2' => '2', // @translate
                        '3' => '3', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_info_default_version',
                ],
            ])

            ->add([
                'name' => 'imageserver_info_version_append',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Append the version to the url (to be set inside module.config.php currently)', // @translate
                    'info' => 'If set, the version will be appended to the url of the server: "iiif-img/3".', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_info_version_append',
                ],
            ])

            /**
            ->add([
                'name' => 'imageserver_identifier_prefix',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Append a prefix to the url (to be set inside module.config.php currently)', // @translate
                    'info' => 'If set, the prefix will be added after the version: "iiif-img/3/xxx".', // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver_identifier_prefix',
                ],
            ])
            */

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

            // Limits for all versions.
            ->add([
                'name' => 'imageserver_image_creator',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Image processor', // @translate
                    'info' => $this->translate('Generally, GD is a little faster than ImageMagick, but ImageMagick manages more formats.') // @translate
                        . ' ' . $this->translate('Nevertheless, the performance depends on your installation and your server.'), // @translate
                    'value_options' => $this->listImageProcessors(),
                ],
                'attributes' => [
                    'id' => 'imageserver_image_creator',
                ],
            ])
            ->add([
                'name' => 'imageserver_image_max_size',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Max dynamic size for images', // @translate
                    'info' => $this->translate('Set the maximum size in bytes for the dynamic processing of images.') // @translate
                        . ' ' . $this->translate('Beyond this limit, the plugin will require a tiled image.') // @translate
                        . ' ' . $this->translate('Let empty to allow processing of any image.'), // @translate
                ],
                'attributes' => [
                    'id' => 'imageserver-image-max-size',
                ],
            ])
            ->add([
                'name' => 'imageserver_image_tile_type',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Tiling type', // @translate
                    'info' => $this->translate('Deep Zoom Image is a free proprietary format from Microsoft largely supported.') // @translate
                        . ' ' . $this->translate('Zoomify is an old format that was largely supported by proprietary softwares and free viewers.') // @translate
                        . ' ' . $this->translate('All formats are served as native by default, but may be served as IIIF too when a viewer request it.'), // @translate
                    'value_options' => [
                        'deepzoom' => 'Deep Zoom Image', // @translate
                        'zoomify' => 'Zoomify', // @translate
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
                    'info' => $this->translate('This query will be used to select all items whose attached images will be prepared in the background.') // @translate
                        . ' ' . $this->translate('Warning: The renderer of all tiled images will be set to "tile".'), // @translate
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
                        '0' => 'Skip media with existing tiles', // @translate
                        '1' => 'Remove existing tiles', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'remove_destination',
                    'value' => '0',
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
                'name' => 'imageserver_image_creator',
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

    protected function translate($args)
    {
        $translator = $this->getTranslator();
        return $translator->translate($args);
    }

    /**
     * Check and return the list of available processors.
     *
     * @todo Merge with ImageServer\Module::listImageProcessors()
     *
     * @return array Associative array of available processors.
     */
    protected function listImageProcessors()
    {
        $processors = [];
        $processors['Auto'] = 'Automatic (GD when possible, else Imagick, else command line)'; // @translate
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
}
