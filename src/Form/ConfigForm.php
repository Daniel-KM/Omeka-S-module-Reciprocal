<?php declare(strict_types=1);

namespace Reciprocal\Form;

use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'reciprocal_reciprocities',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Reciprocal proprerties', // @translate
                    'info' => <<<'TXT'
                    List of reciprocal properties used to fill the linked resources when filled with a value with data type "resource".
                    It is useless to fill the reverse side of properties.
                    The reciprocal value is filled only if the user has the rights to update the linked resource.
                    TXT, // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'reciprocal_reciprocities',
                    'rows' => 30,
                    'placeholder' => <<<'TXT'
                    dcterms:isPartOf = dcterms:hasPart
                    dcterms:relation = dcterms:relation
                    TXT,
                ],
            ])
        ;
    }
}
