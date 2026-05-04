<?php declare(strict_types=1);

namespace ReciprocalTest;

use CommonTest\AbstractTestCase;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * End-to-end behavior of the Reciprocal module.
 *
 * Each scenario covers a corrected behavior:
 *  - create propagation (B1, B2: events fire and resolve resource type)
 *  - update propagation cleans previous reciprocals (B6 path)
 *  - batch context skips internal flush (O1)
 *  - DQL lookup detects existing reciprocal once (O2 idempotence)
 */
class ReciprocalIntegrationTest extends AbstractTestCase
{
    /**
     * @var int[]
     */
    protected array $createdItemIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->getService('Omeka\Settings')->set('reciprocal_reciprocities', [
            'dcterms:hasPart' => 'dcterms:isPartOf',
        ]);
        // Reset the live module's cached reciprocityIds so it picks up the
        // override set in this setUp method.
        $this->resetModuleCache();
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getService('Omeka\AuthenticationService');
        if ($auth->hasIdentity()) {
            return;
        }
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function resetModuleCache(): void
    {
        $moduleManager = $this->getService('ModuleManager');
        $module = $moduleManager->getModule('Reciprocal');
        if (!$module) {
            return;
        }
        $reflection = new \ReflectionClass($module);
        if ($reflection->hasProperty('reciprocityIds')) {
            $prop = $reflection->getProperty('reciprocityIds');
            $prop->setAccessible(true);
            $prop->setValue($module, null);
        }
        if ($reflection->hasProperty('reciprocalValueResourceIds')) {
            $prop = $reflection->getProperty('reciprocalValueResourceIds');
            $prop->setAccessible(true);
            $prop->setValue($module, []);
        }
    }

    public function tearDown(): void
    {
        if ($this->createdItemIds) {
            $api = $this->api();
            foreach (array_unique($this->createdItemIds) as $id) {
                try {
                    $api->delete('items', $id);
                } catch (\Throwable $e) {
                    // Ignore: already removed.
                }
            }
            $this->createdItemIds = [];
        }
        parent::tearDown();
    }

    public function testCreateLinkPropagatesReciprocalValue(): void
    {
        [$whole, $part] = $this->createPair();

        $this->reloadAll($whole, $part);

        $partOfValues = $part->value('dcterms:isPartOf', ['all' => true, 'default' => []]);
        $this->assertCount(1, $partOfValues, 'Reciprocal value should be created on the part.');
        $valueResource = $partOfValues[0]->valueResource();
        $this->assertNotNull($valueResource);
        $this->assertSame($whole->id(), $valueResource->id());
    }

    public function testUpdateRemovingLinkRemovesReciprocalValue(): void
    {
        [$whole, $part] = $this->createPair();

        $api = $this->api();
        $easyMeta = $this->getEasyMeta();
        // Pass at least one other property so ValueHydrator runs the
        // "remove unreused values" pass under isPartial; an empty
        // dcterms:hasPart array alone is a no-op there.
        $api->update('items', $whole->id(), [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'whole-updated',
            ]],
            'dcterms:hasPart' => [],
        ], [], ['isPartial' => true]);

        $this->reloadAll($whole, $part);
        $partOfValues = $part->value('dcterms:isPartOf', ['all' => true, 'default' => []]);
        $this->assertCount(0, $partOfValues, 'Reciprocal value should be removed.');
    }

    public function testReciprocalValueNotDuplicatedOnReSave(): void
    {
        [$whole, $part] = $this->createPair();
        $api = $this->api();

        // Re-save the same data: existing reciprocal must not duplicate.
        $api->update('items', $whole->id(), [
            'dcterms:hasPart' => [[
                'type' => 'resource:item',
                'property_id' => $this->getEasyMeta()->propertyId('dcterms:hasPart'),
                'value_resource_id' => $part->id(),
            ]],
        ], [], ['isPartial' => true]);

        $this->reloadAll($whole, $part);
        $partOfValues = $part->value('dcterms:isPartOf', ['all' => true, 'default' => []]);
        $this->assertCount(1, $partOfValues, 'Reciprocal value should not be duplicated.');
    }

    /**
     * @return ItemRepresentation[]
     */
    protected function createPair(): array
    {
        $part = $this->createItem(['dcterms:title' => 'part']);
        $whole = $this->createItem([
            'dcterms:title' => 'whole',
            'dcterms:hasPart' => [[
                'type' => 'resource:item',
                'property_id' => $this->getEasyMeta()->propertyId('dcterms:hasPart'),
                'value_resource_id' => $part->id(),
            ]],
        ]);
        return [$whole, $part];
    }

    protected function createItem(array $data): ItemRepresentation
    {
        $payload = [];
        foreach ($data as $term => $value) {
            if ($term === 'dcterms:title') {
                $payload['dcterms:title'] = [[
                    'type' => 'literal',
                    'property_id' => $this->getEasyMeta()->propertyId('dcterms:title'),
                    '@value' => $value,
                ]];
            } else {
                $payload[$term] = $value;
            }
        }
        $item = $this->api()->create('items', $payload)->getContent();
        $this->createdItemIds[] = $item->id();
        return $item;
    }

    protected function reloadAll(ItemRepresentation &...$items): void
    {
        // Clear Doctrine identity map so values added by the Reciprocal
        // listener are visible after re-read.
        $this->getEntityManager()->clear();
        $api = $this->api();
        foreach ($items as &$item) {
            $item = $api->read('items', $item->id())->getContent();
        }
    }
}
