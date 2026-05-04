<?php declare(strict_types=1);

namespace ReciprocalTest;

use CommonTest\AbstractTestCase;
use Reciprocal\Module;

/**
 * Verify resource type constants and reciprocity-id resolution.
 */
class ReciprocityIdsTest extends AbstractTestCase
{
    public function testResourceTypesConstantCoversCoreResources(): void
    {
        $this->assertSame(
            [
                'items' => 'resource:item',
                'item_sets' => 'resource:itemset',
                'media' => 'resource:media',
            ],
            Module::RESOURCE_TYPES
        );
    }

    public function testPrepareReciprocityIdsReturnsFalseWhenNoSetting(): void
    {
        $module = $this->buildModule([]);
        $this->assertFalse($this->invokePrepareReciprocityIds($module));
        $this->assertSame(
            [],
            $this->getReciprocityIds($module)
        );
    }

    public function testPrepareReciprocityIdsResolvesSymmetricPair(): void
    {
        $module = $this->buildModule([
            'dcterms:hasPart' => 'dcterms:isPartOf',
        ]);
        $this->assertTrue($this->invokePrepareReciprocityIds($module));

        $easyMeta = $this->getEasyMeta();
        $hasPartId = $easyMeta->propertyId('dcterms:hasPart');
        $isPartOfId = $easyMeta->propertyId('dcterms:isPartOf');

        $ids = $this->getReciprocityIds($module);
        $this->assertSame($isPartOfId, $ids[$hasPartId]);
        $this->assertSame($hasPartId, $ids[$isPartOfId]);
    }

    public function testPrepareReciprocityIdsIgnoresUnknownTerms(): void
    {
        $module = $this->buildModule([
            'dcterms:hasPart' => 'dcterms:isPartOf',
            'unknown:property' => 'still:unknown',
        ]);
        $this->assertTrue($this->invokePrepareReciprocityIds($module));

        $easyMeta = $this->getEasyMeta();
        $hasPartId = $easyMeta->propertyId('dcterms:hasPart');
        $isPartOfId = $easyMeta->propertyId('dcterms:isPartOf');

        $ids = $this->getReciprocityIds($module);
        $this->assertCount(2, $ids);
        $this->assertArrayHasKey($hasPartId, $ids);
        $this->assertArrayHasKey($isPartOfId, $ids);
    }

    public function testPrepareReciprocityIdsHandlesSelfReciprocal(): void
    {
        $module = $this->buildModule([
            'dcterms:relation' => 'dcterms:relation',
        ]);
        $this->assertTrue($this->invokePrepareReciprocityIds($module));

        $relationId = $this->getEasyMeta()->propertyId('dcterms:relation');
        $ids = $this->getReciprocityIds($module);
        $this->assertSame($relationId, $ids[$relationId]);
    }

    public function testPrepareReciprocityIdsCachedAfterFirstCall(): void
    {
        $module = $this->buildModule([
            'dcterms:hasPart' => 'dcterms:isPartOf',
        ]);
        $this->assertTrue($this->invokePrepareReciprocityIds($module));
        // Second call returns cached result without re-querying settings.
        $this->assertTrue($this->invokePrepareReciprocityIds($module));
    }

    protected function buildModule(array $reciprocities): Module
    {
        $services = $this->getServiceManager();
        $services->get('Omeka\Settings')->set('reciprocal_reciprocities', $reciprocities);

        $module = new Module();
        $module->setServiceLocator($services);
        return $module;
    }

    protected function invokePrepareReciprocityIds(Module $module): bool
    {
        $reflection = new \ReflectionMethod($module, 'prepareReciprocityIds');
        $reflection->setAccessible(true);
        return (bool) $reflection->invoke($module);
    }

    protected function getReciprocityIds(Module $module): array
    {
        $property = new \ReflectionProperty($module, 'reciprocityIds');
        $property->setAccessible(true);
        return $property->getValue($module) ?? [];
    }
}
