<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\Manager as AdapterManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

trait PreviousNextResourceTrait
{
    /**
     * @var AdapterManager
     */
    protected $adapterManager;

    /**
     * @var SiteRepresentation
     */
    protected $site;

    /**
     * @var AbstractResourceEntityAdapter
     */
    protected $adapter;

    public function __construct(AdapterManager $adapterManager, SiteRepresentation $site = null)
    {
        $this->adapterManager = $adapterManager;
        $this->site = $site;
    }

    /**
     * Get the public resource immediately before or following the current one.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param string $lowerOrGreaterThan "<" or ">".
     * @return AbstractResourceEntityRepresentation|null
     */
    protected function previousOrNextResource(AbstractResourceEntityRepresentation $resource, $lowerOrGreaterThan)
    {
        $resourceName = $resource->resourceName();
        $this->adapter = $this->adapterManager->get($resourceName);

        $resourceTypes = [
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
        ];
        $resourceType = $resourceTypes[$resourceName];

        // Visibility is automatically managed.

        $entityManager = $this->adapter->getEntityManager();
        $qb = $entityManager->createQueryBuilder()
            ->select('omeka_root.id')
            ->from($resourceType, 'omeka_root');

        $hasQuery = false;

        if ($this->site) {
            switch ($resourceName) {
                case 'items':
                    $this->filterItemsBySite($qb);
                    $hasQuery = $this->filterAndSortResources($qb, 'next_prevnext_items_query');
                    break;
                case 'item_sets':
                    $this->filterItemSetsBySite($qb);
                    $hasQuery = $this->filterAndSortResources($qb, 'next_prevnext_item_sets_query');
                    break;
                case 'media':
                default:
                    break;
            }
        }

        $qb->groupBy('omeka_root.id');

        // Because it seems complex to get prev/next with doctrine in particular
        // when row_number() is not available, all ids are returned, that is
        // quick anyway.
        // TODO Find the previous or next row via doctrine with a query.
        // TODO Make the result static for prev/next.
        if ($hasQuery) {
            $qb
                ->addOrderBy('omeka_root.id', 'ASC');
            $result = array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
            $index = array_search($resource->id(), $result);
            if ($index === false) {
                return null;
            }
            $lowerOrGreaterThan === '<' ? --$index : ++$index;
            return isset($result[$index])
                ? $this->getView()->api()->read($resourceName, $result[$index])->getContent()
                : null;
        }

        $qb
            ->andWhere('omeka_root.id ' . $lowerOrGreaterThan . ' :resource_id')
            ->setParameter(':resource_id', $resource->id())
            ->addOrderBy('omeka_root.id', $lowerOrGreaterThan === '<' ? 'DESC' : 'ASC')
            ->setMaxResults(1);
        $result = $qb->getQuery()->getResult();
        return empty($result[0]['id'])
            ? null
            : $this->getView()->api()->read($resourceName, $result[0]['id'])->getContent();
    }

    /**
     * Filter a query for resources.
     *
     * @see \Omeka\Api\Adapter\ItemAdapter::buildQuery()
     * @return bool Indicate if there is a query or not.
     */
    protected function filterAndSortResources(QueryBuilder $qb, string $settingName): bool
    {
        if (!$this->adapter) {
            return false;
        }

        $query = $this->site
            ? $this->getView()->siteSetting($settingName)
            : $this->getView()->setting($settingName);
        if (!$query) {
            return false;
        }

        $originalQuery = ltrim((string) $query, "? \t\n\r\0\x0B");
        parse_str($originalQuery, $query);
        if (!$query) {
            return false;
        }

        $this->adapter->buildBaseQuery($qb, $query);
        $this->adapter->buildQuery($qb, $query);
        $this->adapter->sortQuery($qb, $query);
        return true;
    }

    /**
     * Filter a query for items by site.
     *
     * @see \Omeka\Api\Adapter\ItemAdapter::buildQuery()
     */
    protected function filterItemsBySite(QueryBuilder $qb): void
    {
        if (!$this->adapter || !$this->site) {
            return;
        }

        $siteAlias = $this->adapter->createAlias();
        $qb->innerJoin(
            'omeka_root.sites', $siteAlias, 'WITH', $qb->expr()->eq(
                "$siteAlias.id",
                $this->adapter->createNamedParameter($qb, $this->site->id())
            )
        );
    }

    /**
     * Filter a query for item sets by site.
     *
     * @see \Omeka\Api\Adapter\ItemSetAdapter::buildQuery()
     */
    protected function filterItemSetsBySite(QueryBuilder $qb): void
    {
        if (!$this->adapter || !$this->site) {
            return;
        }

        $siteItemSetsAlias = $this->adapter->createAlias();
        $qb->innerJoin(
            'omeka_root.siteItemSets',
            $siteItemSetsAlias
        );
        $qb->andWhere($qb->expr()->eq(
            "$siteItemSetsAlias.site",
            $this->adapter->createNamedParameter($qb, $this->site->id()))
        );
        $qb->addOrderBy("$siteItemSetsAlias.position", 'ASC');
    }
}
