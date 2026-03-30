<?php declare(strict_types=1);

namespace ImageServer\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Image Server'; // @translate

    protected $elementGroups = [
        'image_server' => 'Image server', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'image-server')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'imageserver_default_thumbnail_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'image_server',
                    'label' => 'Default image display on public pages', // @translate
                    'info' => 'Specify how images are displayed on item and media pages. "Large" shows the standard Omeka large thumbnail. "Tile" renders images in a zoomable OpenSeadragon viewer using pre-tiled data when available. "Original" serves the original file (not recommended for large images). This is independent of IIIF viewers (Universal Viewer, Mirador, Diva…).', // @translate
                    'value_options' => [
                        'large' => 'Large thumbnail (default)', // @translate
                        'tile' => 'Zoomable viewer (OpenSeadragon with tiles)', // @translate
                        'original' => 'Original file (not recommended for images larger than 1-10 MB)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_default_thumbnail_type',
                ],
            ])
            ->add([
                'name' => 'imageserver_tile_fallback',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'image_server',
                    'label' => 'Fallback when image is not pre-tiled', // @translate
                    'info' => 'When "Zoomable viewer" is selected above but the image is not pre-tiled, this setting controls what is displayed instead. "Zoomable with large" loads the large thumbnail in OpenSeadragon (limited zoom). "Large thumbnail" falls back to the standard display. "Zoomable with original" loads the original file in OpenSeadragon (good zoom but slow for large files).', // @translate
                    'value_options' => [
                        'tile_large' => 'Zoomable with large thumbnail (OpenSeadragon)', // @translate
                        'large' => 'Large thumbnail', // @translate
                        'tile_original' => 'Zoomable with original file (OpenSeadragon)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_tile_fallback',
                ],
            ])
        ;
    }
}
