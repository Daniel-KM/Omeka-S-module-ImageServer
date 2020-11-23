<?php declare(strict_types=1);

namespace ImageServer\Form;

use ImageServer\Form\Element\OptionalRadio;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Image Server'; // @translate

    public function init(): void
    {
        $this
            ->add([
                'name' => 'imageserver_default_thumbnail_type',
                'type' => OptionalRadio::class,
                'options' => [
                    'label' => 'Default display of images', // @translate
                    'info' => 'To use the original file is not recommended when files are bigger than 1-10 MB.', // @translate
                    'value_options' => [
                        'tile' => 'Tile', // @translate
                        'large' => 'Large', // @translate
                        'original' => 'Original', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_default_thumbnail_type',
                ],
            ])
            ->add([
                'name' => 'imageserver_tile_fallback',
                'type' => OptionalRadio::class,
                'options' => [
                    'label' => 'Fallback when there is no tile', // @translate
                    'info' => 'To use the original file is not recommended when files are bigger than 1-10 MB.', // @translate
                    'value_options' => [
                        'tile_large' => 'Tile with large thumbnail', // @translate
                        'large' => 'Large thumbnail', // @translate
                        'tile_original' => 'Tile with original file', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'imageserver_tile_fallback',
                ],
            ])
        ;
    }
}
