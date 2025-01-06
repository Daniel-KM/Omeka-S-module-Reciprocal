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
        $resource = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->read($request->getResource(), $request->getId(), [], ['responseContent' => 'resource'])->getContent();
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
            foreach ($reciprocalResources as $reciprocalResourceId => $isNew) {
                /** @var \Omeka\Entity\Resource $reciprocalResource */
                $reciprocalResource = $entityManager->getReference(\Omeka\Entity\Resource::class, $reciprocalResourceId);
                $reciprocalValues = $reciprocalResource->getValues();
                $existings = $reciprocalValues->filter(function ($value) use ($resource, $resourceType, $reciprocalPropertyId) {
                    $type = $value->getType();
                    return ($type === 'resource' || $type === $resourceType)
                        && $value->getProperty()->getId() === $reciprocalPropertyId
                        && $value->getValueResource() === $resource;
                });
                if ($isNew) {
                    // Add the resource as a value in the reciprocal property of
                    // the reciprocal resource one time only.
                    if (!count($existings)) {
                        $toFlush = true;
                        $reciprocalValue = new \Omeka\Entity\Value;
                        $reciprocalValue->setResource($reciprocalResource);
                        $reciprocalValue->setProperty($reciprocalProperty);
                        $reciprocalValue->setType($resourceType);
                        $reciprocalValue->setValueResource($resource);
                        $reciprocalValue->setIsPublic($this->isPublicProperty($resource, $reciprocalProperty));
                        $reciprocalValues->add($reciprocalValue);
                        $entityManager->persist($reciprocalValue);
                    }
                }
                // Remove the resource as a value in the reciprocal property of
                // the reciprocal resource for all values.
                elseif (count($existings)) {
                    $toFlush = true;
                    foreach ($existings as $existing) {
                        $reciprocalValues->removeElement($existing);
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

        // Flush in all cases in Omeka S v4, for background or foreground batch
        // edit process, because this is in api post.
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

        // Use ids to quick process.
        $this->reciprocityIds = array_combine(
            array_replace($reciprocities, array_intersect_key($propertyIdsByTerms, $reciprocities)),
            array_replace(array_flip($reciprocities), array_intersect_key($propertyIdsByTerms, array_flip($reciprocities)))
        );
        return true;
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
            // Process is true (new value resource to append) or false (old
            // value resource to delete).
            // The reciprocal resource cannot be stored here, because an issue
            // may occur with entity manager flush/clear.
            $this->reciprocalValueResourceIds[$resourceId][$propertyId][$reciprocalResource->getId()] = $isNew;
        }
    }

    protected function isPublicProperty(Resource $resource, Property $property)
    {
        static $isPublicProperties = [];

        $resourceTemplate = $resource->getResourceTemplate();
        if (!$resourceTemplate) {
            return true;
        }

        $resourceTemplateId = $resourceTemplate->getId();
        $propertyId = $property->getId();
        if (!isset($isPublicProperties[$resourceTemplateId][$propertyId])) {
            foreach ($resourceTemplate->getResourceTemplateProperties() as $resourceTemplateProperty) {
                if ($resourceTemplateProperty->getProperty()->getId() === $propertyId) {
                    $isPublicProperties[$resourceTemplateId][$propertyId] = !$resourceTemplateProperty->getIsPrivate();
                    break;
                }
            }
        }

        return !isset($isPublicProperties[$resourceTemplateId][$propertyId])
            || $isPublicProperties[$resourceTemplateId][$propertyId];
    }
}
