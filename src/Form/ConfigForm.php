<?php
namespace Reciprocal\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init()
    {
        $this
            ->add([
                'name' => 'reciprocal_reciprocities',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Reciprocal proprerties', // @translate
                    'info' => 'List of reciprocal properties used to fill the linked resources when filled with a value with data type "resource".
It is useless to fill the reverse side of properties.
The reciprocal value is filled only if the user has the rights to update the linked resource.', // @translate
                ],
                'attributes' => [
                    'id' => 'reciprocal_reciprocities',
                    'rows' => 30,
                    'placeholder' => 'dcterms:isPartOf = dcterms:hasPart
dcterms:relation = dcterms:relation
',
                ],
            ])
        ;

        $this->getInputFilter()
            ->add([
                'name' => 'reciprocal_reciprocities',
                'required' => false,
                'filters' => [
                    [
                        'name' => \Laminas\Filter\Callback::class,
                        'options' => [
                            'callback' => [$this, 'stringToKeyValues'],
                        ],
                    ],
                ],
            ])
        ;
    }

    public function stringToKeyValues($string)
    {
        if (is_array($string)) {
            return $string;
        }
        $result = [];
        $list = $this->stringToList($string);
        foreach ($list as $keyValue) {
            list($key, $value) = array_map('trim', explode('=', $keyValue, 2));
            if ($key !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Get each line of a string separately.
     *
     * @param string $string
     * @return array
     */
    protected function stringToList($string)
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     *
     * @param string $string
     * @return string
     */
    protected function fixEndOfLine($string)
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $string);
    }
}
