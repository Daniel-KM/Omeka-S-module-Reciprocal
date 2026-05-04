<?php declare(strict_types=1);

namespace Reciprocal;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Property;
use Omeka\Entity\Resource;
use Omeka\Module\AbstractModule;
use Reciprocal\Form\ConfigForm;

/**
 * Reciprocal.
 *
 * @copyright Daniel Berthereau, 2020-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    /**
     * @var array|null
     */
    protected $reciprocityIds;

    /**
     * @var array
     */
    protected $reciprocalValueResourceIds = [];

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.65'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $adapters = [
            \Omeka\Api\Adapter\ItemAdapter::class,
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            \Omeka\Api\Adapter\MediaAdapter::class,
        ];
        foreach ($adapters as $adapter) {
            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleApiResourcePre']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.create.post',
                [$this, 'handleApiResourcePost']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.post',
                [$this, 'handleApiResourcePost']
            );
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');

        // TODO See module Guest.
        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');
        if (is_null($data)) {
            return '';
        }

        $setting = $settings->get('reciprocal_reciprocities', []);
        $value = '';
        foreach ($setting as $key => $val) {
            $value .= $key . ' = ' . $val . "\n";
        }
        $data['reciprocal_reciprocities'] = $value;

        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $form->setData($data);

        return $renderer->formCollection($form);
    }

    /**
     * @param Event $event
     */
    public function handleApiResourcePre(Event $event): void
    {
        if (!$this->prepareReciprocityIds()) {
            return;
        }

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        // Fetch the persisted entity directly via the entity manager to avoid
        // a full api.read event chain (ACL, listeners) on every update.
        /** @var \Omeka\Api\Adapter\AbstractEntityAdapter $adapter */
        $adapter = $event->getTarget();
        $entityClass = $adapter && method_exists($adapter, 'getEntityClass')
            ? $adapter->getEntityClass()
            : null;
        if (!$entityClass) {
            return;
        }
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $resource = $entityManager->find($entityClass, $request->getId());
        if (!$resource instanceof Resource) {
            return;
        }
        $this->prepareSpecifiedValuesResources($resource, false);
    }

    /**
     * @param Event $event
     */
    public function handleApiResourcePost(Event $event): void
    {
        // Add or remove reciprocal values for value resources.
        // The process takes care of: auto-referencement, duplicate value
        // resources, rights to update the reciprocal resource, single creation/update
        // and batch creation/update.

        // The check is done here too, because the ids are not prepared yet for
        // creation.
        if (!$this->prepareReciprocityIds()) {
            return;
        }

        /**
         * @var \Omeka\Entity\Resource $resource
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $resourceTypes = [
            // "Resource" is used to simplify checks.
            'resource' => 'resource',
            'items' => 'resource:item',
            'item_sets' => 'resource:itemset',
            'media' => 'resource:media',
        ];

        $resource = $event->getParam('response')->getContent();
        $resourceName = $resource->getResourceName();
        $resourceType = $resourceTypes[$resourceName];
        $resourceId = $resource->getId();

        $this->prepareSpecifiedValuesResources($resource, true);

        $toFlush = false;
        foreach ($this->reciprocalValueResourceIds[$resourceId] as $propertyId => $reciprocalResources) {
            $reciprocalPropertyId = $this->reciprocityIds[$propertyId];
            $reciprocalProperty = $entityManager->getReference(\Omeka\Entity\Property::class, $reciprocalPropertyId);
            foreach ($reciprocalResources as $reciprocalResourceId => $entry) {
                $isNew = (bool) ($entry['isNew'] ?? false);
                $sourceIsPublic = (bool) ($entry['sourceIsPublic'] ?? true);
                /** @var \Omeka\Entity\Resource $reciprocalResource */
                $reciprocalResource = $entityManager->getReference(\Omeka\Entity\Resource::class, $reciprocalResourceId);
                $existings = $this->findReciprocalValues(
                    $entityManager,
                    $reciprocalResourceId,
                    $reciprocalPropertyId,
                    $resource,
                    $resourceType
                );
                if ($isNew) {
                    // Add the resource as a value in the reciprocal property of
                    // the reciprocal resource one time only.
                    if (!$existings) {
                        $toFlush = true;
                        $reciprocalValue = new \Omeka\Entity\Value;
                        $reciprocalValue->setResource($reciprocalResource);
                        $reciprocalValue->setProperty($reciprocalProperty);
                        $reciprocalValue->setType($resourceType);
                        $reciprocalValue->setValueResource($resource);
                        $reciprocalValue->setIsPublic($this->resolveIsPublic(
                            $reciprocalResource,
                            $reciprocalProperty,
                            $sourceIsPublic
                        ));
                        $entityManager->persist($reciprocalValue);
                    }
                }
                // Remove the resource as a value in the reciprocal property of
                // the reciprocal resource for all values.
                elseif ($existings) {
                    $toFlush = true;
                    foreach ($existings as $existing) {
                        $entityManager->remove($existing);
                    }
                }
            }
        }

        if (!$toFlush) {
            return;
        }

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');

        // Defer flushing when the request explicitly opts out (batch edit
        // pipeline). Doctrine will flush new/removed entities once at the end.
        if ($request && !$request->getOption('flushEntityManager', true)) {
            return;
        }

        $entityManager->flush();
    }

    /**
     * Convert the table of reciprocty terms into a table of reciprocity ids.
     *
     * @return bool False when there are no mapping o reciprocities.
     */
    protected function prepareReciprocityIds()
    {
        // Early skip when there is no reciprocities or already prepared.
        if (!is_null($this->reciprocityIds)) {
            return !empty($this->reciprocityIds);
        }

        // TODO Store this list in a specific setting to avoid to rebuild it each time (keep the original settings too)?
        $this->reciprocityIds = [];

        $services = $this->getServiceLocator();
        $reciprocities = $services->get('Omeka\Settings')->get('reciprocal_reciprocities');
        if (empty($reciprocities)) {
            return false;
        }

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $services->get('Common\EasyMeta');

        $propertyIdsByTerms = $easyMeta->propertyIds();
        $propertyTermsByIds = $easyMeta->propertyTerms();

        // Manage the case where some properties were removed.
        $reciprocities = array_intersect_key($reciprocities, $propertyIdsByTerms);
        $reciprocities = array_intersect($reciprocities, $propertyTermsByIds);

        // Merge the flipped array to manage all cases one time.
        $reciprocities += array_flip($reciprocities);

        // Translate term pairs to id pairs without relying on array_combine,
        // which throws on size mismatch when terms could not be resolved.
        $idPairs = [];
        foreach ($reciprocities as $term => $reciprocalTerm) {
            $id = $propertyIdsByTerms[$term] ?? null;
            $reciprocalId = $propertyIdsByTerms[$reciprocalTerm] ?? null;
            if ($id && $reciprocalId) {
                $idPairs[$id] = $reciprocalId;
            }
        }
        $this->reciprocityIds = $idPairs;
        return !empty($this->reciprocityIds);
    }

    /**
     * Create a list of reciprocal resource values to add or to delete.
     *
     * @param Resource $resource
     * @param bool $isNew
     */
    protected function prepareSpecifiedValuesResources(Resource $resource, $isNew): void
    {
        // Avoid an infinite loop.
        static $processed = [];
        $resourceId = $resource->getId();
        if (isset($processed[$resourceId][(int) $isNew])) {
            return;
        }

        $processed[$resourceId][(int) $isNew] = true;

        if (!isset($this->reciprocalValueResourceIds[$resourceId])) {
            $this->reciprocalValueResourceIds[$resourceId] = [];
        }

        /**
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Permissions\Acl $acl
         * @var \Omeka\Entity\User $user
         */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();

        $resourceTypes = [
            // "Resource" is used to simplify checks.
            'resources' => 'resource',
            'items' => 'resource:item',
            'item_sets' => 'resource:itemset',
            'media' => 'resource:media',
        ];

        foreach ($resource->getValues() as $value) {
            $type = $value->getType();
            if (!in_array($type, $resourceTypes)) {
                continue;
            }
            $propertyId = $value->getProperty()->getId();
            if (!isset($this->reciprocityIds[$propertyId])) {
                continue;
            }
            // Skip auto-referenced resource.
            // Check user rights to modify the reciprocal resource.
            $reciprocalResource = $value->getValueResource();
            if ($reciprocalResource === $resource
                || !$acl->isAllowed($user, $reciprocalResource, 'update')
            ) {
                continue;
            }
            // The reciprocal resource id allows to manage duplicate values.
            // The reciprocal resource cannot be stored here, because an issue
            // may occur with entity manager flush/clear. Keep the source value
            // visibility so the reciprocal value can inherit it when the
            // reciprocal template does not declare the property.
            $this->reciprocalValueResourceIds[$resourceId][$propertyId][$reciprocalResource->getId()] = [
                'isNew' => $isNew,
                'sourceIsPublic' => (bool) $value->getIsPublic(),
            ];
        }
    }

    /**
     * Fetch existing reciprocal values via a targeted DQL query.
     *
     * @return \Omeka\Entity\Value[]
     */
    protected function findReciprocalValues(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        int $reciprocalResourceId,
        int $reciprocalPropertyId,
        Resource $sourceResource,
        string $sourceResourceType
    ): array {
        $dql = <<<'DQL'
            SELECT v FROM Omeka\Entity\Value v
            WHERE IDENTITY(v.resource) = :reciprocalResource
                AND IDENTITY(v.property) = :reciprocalProperty
                AND IDENTITY(v.valueResource) = :sourceResource
                AND v.type IN (:types)
        DQL;
        return $entityManager->createQuery($dql)
            ->setParameter('reciprocalResource', $reciprocalResourceId)
            ->setParameter('reciprocalProperty', $reciprocalPropertyId)
            ->setParameter('sourceResource', $sourceResource->getId())
            ->setParameter('types', ['resource', $sourceResourceType])
            ->getResult();
    }

    /**
     * Resolve the visibility of a reciprocal value.
     *
     * The reciprocal resource template, when it declares the reciprocal
     * property, drives visibility. When it does not, inherit the source
     * value's visibility so a private source does not leak as a public
     * reciprocal.
     */
    protected function resolveIsPublic(
        Resource $reciprocalResource,
        Property $reciprocalProperty,
        bool $sourceIsPublic
    ): bool {
        static $cache = [];

        $resourceTemplate = $reciprocalResource->getResourceTemplate();
        if (!$resourceTemplate) {
            return $sourceIsPublic;
        }

        $resourceTemplateId = $resourceTemplate->getId();
        $propertyId = $reciprocalProperty->getId();
        if (!array_key_exists($propertyId, $cache[$resourceTemplateId] ?? [])) {
            $cache[$resourceTemplateId][$propertyId] = null;
            foreach ($resourceTemplate->getResourceTemplateProperties() as $resourceTemplateProperty) {
                if ($resourceTemplateProperty->getProperty()->getId() === $propertyId) {
                    $cache[$resourceTemplateId][$propertyId] = !$resourceTemplateProperty->getIsPrivate();
                    break;
                }
            }
        }

        $templateIsPublic = $cache[$resourceTemplateId][$propertyId];
        return $templateIsPublic ?? $sourceIsPublic;
    }
}
