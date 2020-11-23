<?php declare(strict_types=1);

namespace ImageServer\View\Helper;

use Laminas\Form\ElementInterface;
use Laminas\View\Helper\AbstractHelper;

class FormNote extends AbstractHelper
{
    /**
     * Generate a static text for a form.
     *
     * @see \Laminas\Form\View\Helper\FormLabel
     *
     * @param ElementInterface $element
     * @param null|string $labelContent
     * @param string $position
     * @return string|FormNote
     */
    public function __invoke(ElementInterface $element = null, $labelContent = null, $position = null)
    {
        if (!$element) {
            return $this;
        }

        return $this->render($element);
    }

    public function render(ElementInterface $element)
    {
        $content = $element->getOption('html');
        if ($content) {
            return $content;
        }

        $view = $this->getView();
        return $this->openTag($element)
            . $view->escapeHtml($view->translate($element->getOption('text')))
            . $this->closeTag();
    }

    /**
     * Generate an opening label tag.
     *
     * @param null|array|ElementInterface $attributesOrElement
     * @return string
     */
    public function openTag($attributesOrElement = null)
    {
        if (empty($attributesOrElement)) {
            return '<p>';
        }

        if (is_array($attributesOrElement)) {
            $attributes = $this->createAttributesString($attributesOrElement);
            return sprintf('<p %s>', $attributes);
        }

        return '<p>';
    }

    /**
     * Return a closing label tag.
     *
     * @return string
     */
    public function closeTag()
    {
        return '</p>';
    }

    /**
     * Determine input type to use
     *
     * @param  ElementInterface $element
     * @return string
     */
    protected function getType(ElementInterface $element)
    {
        return 'note';
    }
}
