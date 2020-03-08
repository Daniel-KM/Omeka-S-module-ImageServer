<?php
namespace ImageServer\Form;

use Omeka\Form\Element\PropertySelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    public function init()
    {
        $this
            ->add([
                'name' => 'imageserver_info_version',
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
                    'id' => 'imageserver_info_version',
                ],
            ])

            ->add([
                'name' => 'imageserver_info_rights',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Rights', // @translate
                    'value_options' => [
                        'none' => 'No mention', // @translate
                        'url' => 'Specified license url below', // @translate
                        'property' => 'Specified property below', // @translate
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
                'name' => 'imageserver_bulk_tiler',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Bulk tiler', // @translate
                    'info' => 'Imported files can be tiled via a background job.', // @translate
                ],
            ])
        ;
        $bulkFieldset = $this->get('imageserver_bulk_tiler');
        $bulkFieldset
            ->add([
                'name' => 'query',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Query', // @translate
                    'info' => $this->translate('This query will be used to select all items whose attached images will be tiled in the background.') // @translate
                        . ' ' . $this->translate('Warning: The renderer of all tiled images will be set to "tile".'), // @translate
                    'documentation' => 'https://omeka.org/s/docs/user-manual/sites/site_pages/#browse-preview',
                ],
                'attributes' => [
                    'id' => 'query',
                ],
            ])
            ->add([
                'name' => 'remove_destination',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Remove existing tiles', // @translate
                    'info' => 'If checked, existing tiles will be removed, else they will be skipped.',  // @translate
                ],
                'attributes' => [
                    'id' => 'remove-destination',
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
                'name' => 'imageserver_bulk_tiler',
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
