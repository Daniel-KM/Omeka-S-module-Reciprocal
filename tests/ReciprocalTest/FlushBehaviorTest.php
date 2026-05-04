<?php declare(strict_types=1);

namespace ReciprocalTest;

use CommonTest\AbstractTestCase;
use Laminas\EventManager\Event;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Item;
use Reciprocal\Module;

/**
 * Verify that the module defers EntityManager::flush() when the request
 * opts out via the flushEntityManager option (O1).
 */
class FlushBehaviorTest extends AbstractTestCase
{
    public function testHandlerDoesNotFlushWhenRequestOptsOut(): void
    {
        $services = $this->getServiceManager();
        $services->get('Omeka\Settings')->set('reciprocal_reciprocities', [
            'dcterms:hasPart' => 'dcterms:isPartOf',
        ]);

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $module = new Module();
        $module->setServiceLocator($this->wrapLocator($services, [
            'Omeka\EntityManager' => $entityManager,
        ]));

        $resource = new Item();
        $this->setEntityId($resource, 99999);

        $request = new Request('update', 'items');
        $request->setOption('flushEntityManager', false);
        $response = new Response();
        $response->setContent($resource);

        $event = new Event('api.update.post', null, [
            'request' => $request,
            'response' => $response,
        ]);

        $module->handleApiResourcePost($event);
        $this->addToAssertionCount(1);
    }

    protected function wrapLocator($real, array $overrides): \Laminas\ServiceManager\ServiceManager
    {
        // Build a small ServiceManager that delegates to the real one but
        // overrides specific keys.
        $sm = new \Laminas\ServiceManager\ServiceManager();
        foreach ($overrides as $key => $value) {
            $sm->setService($key, $value);
        }
        $sm->setAllowOverride(true);
        // Fallback: copy services on demand.
        $sm->addAbstractFactory(new class($real, $overrides) implements \Laminas\ServiceManager\Factory\AbstractFactoryInterface {
            public function __construct(private $real, private array $overrides) {}
            public function canCreate(\Psr\Container\ContainerInterface $container, $name): bool
            {
                return !isset($this->overrides[$name]) && $this->real->has($name);
            }
            public function __invoke(\Psr\Container\ContainerInterface $container, $name, ?array $options = null)
            {
                return $this->real->get($name);
            }
        });
        return $sm;
    }

    protected function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
