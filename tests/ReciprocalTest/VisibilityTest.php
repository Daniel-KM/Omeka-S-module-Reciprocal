<?php declare(strict_types=1);

namespace ReciprocalTest;

use CommonTest\AbstractTestCase;
use Omeka\Entity\Item;
use Omeka\Entity\Property;
use Omeka\Entity\ResourceTemplate;
use Omeka\Entity\ResourceTemplateProperty;
use Reciprocal\Module;

/**
 * Verify visibility resolution for reciprocal values (S4).
 *
 * resolveIsPublic must:
 *  - inherit the source visibility when the reciprocal template does not
 *    declare the reciprocal property,
 *  - inherit the source visibility when the reciprocal resource has no
 *    template at all,
 *  - obey the template's isPrivate flag when the property is declared.
 */
class VisibilityTest extends AbstractTestCase
{
    public function testInheritsSourceVisibilityWhenNoTemplate(): void
    {
        $module = new Module();
        $resource = new Item();

        $property = $this->makeProperty(123);

        $this->assertTrue($this->invokeResolveIsPublic($module, $resource, $property, true));
        $this->assertFalse($this->invokeResolveIsPublic($module, $resource, $property, false));
    }

    public function testInheritsSourceVisibilityWhenTemplateMissingProperty(): void
    {
        $module = new Module();
        $resource = new Item();
        $template = $this->makeTemplate(1);
        $resource->setResourceTemplate($template);

        $otherProperty = $this->makeProperty(999);
        $this->attachTemplateProperty($template, $otherProperty, false);

        $property = $this->makeProperty(123);

        $this->assertFalse(
            $this->invokeResolveIsPublic($module, $resource, $property, false),
            'Private source must remain private if template stays silent.'
        );
        $this->assertTrue(
            $this->invokeResolveIsPublic($module, $resource, $property, true)
        );
    }

    public function testTemplateMakesReciprocalPrivate(): void
    {
        $module = new Module();
        $resource = new Item();
        $template = $this->makeTemplate(2);
        $resource->setResourceTemplate($template);

        $property = $this->makeProperty(456);
        $this->attachTemplateProperty($template, $property, true);

        $this->assertFalse(
            $this->invokeResolveIsPublic($module, $resource, $property, true),
            'Template private flag overrides public source.'
        );
    }

    public function testTemplateMakesReciprocalPublic(): void
    {
        $module = new Module();
        $resource = new Item();
        $template = $this->makeTemplate(3);
        $resource->setResourceTemplate($template);

        $property = $this->makeProperty(789);
        $this->attachTemplateProperty($template, $property, false);

        $this->assertTrue(
            $this->invokeResolveIsPublic($module, $resource, $property, false),
            'Template public flag overrides private source.'
        );
    }

    protected function invokeResolveIsPublic(
        Module $module,
        $reciprocalResource,
        $reciprocalProperty,
        bool $sourceIsPublic
    ): bool {
        $reflection = new \ReflectionMethod($module, 'resolveIsPublic');
        $reflection->setAccessible(true);
        return (bool) $reflection->invoke(
            $module,
            $reciprocalResource,
            $reciprocalProperty,
            $sourceIsPublic
        );
    }

    protected function makeProperty(int $id): Property
    {
        $property = new Property();
        $this->setEntityId($property, $id);
        return $property;
    }

    protected function makeTemplate(int $id): ResourceTemplate
    {
        $template = new ResourceTemplate();
        $this->setEntityId($template, $id);
        return $template;
    }

    protected function attachTemplateProperty(
        ResourceTemplate $template,
        Property $property,
        bool $isPrivate
    ): void {
        $rtp = new ResourceTemplateProperty();
        $rtp->setProperty($property);
        $rtp->setResourceTemplate($template);
        $rtp->setIsPrivate($isPrivate);
        $template->getResourceTemplateProperties()->add($rtp);
    }

    protected function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
