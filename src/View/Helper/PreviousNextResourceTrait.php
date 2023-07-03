<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use AdvancedSearch\Mvc\Controller\Plugin\SearchResources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Laminas\EventManager\Event;
use Laminas\Session\Container;
use Omeka\Api\Adapter\Manager as ApiAdapterManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Request;

/**
 * @todo Simplify, factorize and clarify process.
 */
trait PreviousNextResourceTrait
{
    /**
     * @var ApiAdapterManager
     */
    protected $apiAdapterManager;

    /**
     * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter
     */
    protected $resourceAdapter;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \AdvancedSearch\Mvc\Controller\Plugin\SearchResources
     */
    protected $searchResources;

    /**
     * @var SiteRepresentation
     */
    protected $site;

    public function __construct(
        ApiAdapterManager $apiAdapterManager,
        Connection $connection,
        EntityManager $entityManager,
        ?SearchResources $searchResources,
        ?SiteRepresentation $site
    ) {
        $this->apiAdapterManager = $apiAdapterManager;
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        $this->searchResources = $searchResources;
        $this->site = $site;
    }

    /**
     * Get the query as string or array from the session or settings or passed.
     */
    protected function getQuery(string $resourceName, ?string $sourceQuery, ?array $query)
    {
        $view = $this->getView();

        if ($sourceQuery === 'session') {
            $ui = $this->site ? 'public' : 'admin';
            $session = new Container('EasyAdmin');
            return $session->lastQuery[$ui][$resourceName] ?? [];
        } elseif ($sourceQuery === 'setting') {
            switch ($resourceName) {
                case 'items':
                    return $this->site
                        ? $view->siteSetting('blockplus_prevnext_items_query', [])
                        : $view->setting('easyadmin_prevnext_items_query', []);
                    break;
                case 'item_sets':
                    return $this->site
                        ? $view->siteSetting('blockplus_prevnext_item_sets_query', [])
                        : $view->setting('easyadmin_prevnext_item_sets_query', []);
                case 'media':
                default:
                    return [];
            }
        } else {
            return $query ?? [];
        }
    }

    protected function getPreviousAndNextResourceIds(AbstractResourceEntityRepresentation $resource, $query): array
    {
        // Because it seems complex to get prev/next with doctrine in particular
        // when row_number() is not available, all ids are returned, that is
        // quick anyway.
        // See previous queries in module Next (version 3.4.46) or in previous
        // version of this module.
        // TODO Check if visibility is automatically managed. Or use standard automatic filter to check visibility.

        $resourceName = $resource->resourceName();

        if (empty($query)) {
            $query = [];
        } elseif (is_string($query)) {
            $q = $query;
            $query = [];
            parse_str($q, $query);
        }

        // First step, get the original query, unchanged, without limit.
        // Ideally, use qb from the adapter directly and return scalar.
        $qb = $this->prepareSearch($resourceName, $query)
            ->setMaxResults(null)
            ->setFirstResult(null);

        if ($this->site && !$query) {
            switch ($resourceName) {
                case 'items':
                    $this->filterItemsBySite($qb);
                    break;
                case 'item_sets':
                    $this->filterItemSetsBySite($qb);
                    break;
                case 'media':
                case 'annotations':
                default:
                    break;
            }
        }

        // Get only ids.
        // Ideally output only three ids; or two ids with order reversed for previous.
        $qb->select('omeka_root.id');
        $ids = $qb->getQuery()->getSingleColumnResult();

        $resourceId = $resource->id();
        $index = array_search($resourceId, $ids);
        if ($index === false) {
            return [null, null];
        }

        $previousIndex = $index - 1;
        $previousResourceId = empty($ids[$previousIndex]) ? null : (int) $ids[$previousIndex];

        $nextIndex = $index + 1;
        $nextResourceId = empty($ids[$nextIndex]) ? null : (int) $ids[$nextIndex];

        return [
            $previousResourceId,
            $nextResourceId,
        ];
    }

    /**
     * Copy of \Omeka\Api\Adapter\AbstractEntityAdapter::search() to get a prepared query builder.
     *
     * @todo Trigger all api manager events (api.execute.pre, etc.).
     * @see \Omeka\Api\Adapter\AbstractEntityAdapter::search()
     */
    protected function prepareSearch($resourceName, array $query): QueryBuilder
    {
        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $this->resourceAdapter = $this->apiAdapterManager->get($resourceName);

        $request = new Request('search', $resourceName);

        // Use specific module Advanced Search adapter if available.
        $override = [];
        if ($this->searchResources) {
            $this->searchResources->setAdapter($this->resourceAdapter);
            $query = $this->searchResources->cleanQuery($query);
            $query = $this->searchResources->startOverrideQuery($query, $override);
            // The process is done during event "api.search.query".
            if (!empty($override)) {
                $request->setOption('override', $override);
            }
        }

        $request->setContent($query);

        // Set default query parameters
        $defaultQuery = [
            'page' => null,
            'per_page' => null,
            'limit' => null,
            'offset' => null,
            'sort_by' => null,
            'sort_order' => null,
        ];
        $query += $defaultQuery;
        $query['sort_order'] = $query['sort_order'] && strtoupper((string) $query['sort_order']) === 'DESC' ? 'DESC' : 'ASC';

        // Begin building the search query.
        $entityClass = $this->resourceAdapter->getEntityClass();

        // $adapter->index = 0;
        $qb = $this->resourceAdapter->getEntityManager()
            ->createQueryBuilder()
            ->select('omeka_root')
            ->from($entityClass, 'omeka_root');
        $this->resourceAdapter->buildBaseQuery($qb, $query);
        $this->resourceAdapter->buildQuery($qb, $query);
        $qb->groupBy('omeka_root.id');

        // Trigger the search.query event.
        $event = new Event('api.search.query', $this->resourceAdapter, [
            'queryBuilder' => $qb,
            'request' => $request,
        ]);
        $this->resourceAdapter->getEventManager()->triggerEvent($event);

        // Finish building the search query. In addition to any sorting the
        // adapters add, always sort by entity ID.
        $this->resourceAdapter->sortQuery($qb, $query);
        $qb->addOrderBy('omeka_root.id', $query['sort_order']);

        return $qb;
    }

    /**
     * Filter a query for resources.
     *
     * @see \Omeka\Api\Adapter\ItemAdapter::buildQuery()
     * @return bool Indicate if there is a query or not.
     */
    protected function filterAndSortResources(QueryBuilder $qb, string $settingName): bool
    {
        if (!$this->resourceAdapter) {
            return false;
        }

        $query = $this->site
            ? $this->getView()->siteSetting($settingName)
            : $this->getView()->setting($settingName);
        if (!$query) {
            return false;
        }

        // TODO Store query as array.
        $originalQuery = ltrim((string) $query, "? \t\n\r\0\x0B");
        parse_str($originalQuery, $query);
        if (!$query) {
            return false;
        }

        $this->resourceAdapter->buildBaseQuery($qb, $query);
        $this->resourceAdapter->buildQuery($qb, $query);
        $this->resourceAdapter->sortQuery($qb, $query);
        return true;
    }

    /**
     * Filter a query for items by site.
     *
     * @see \Omeka\Api\Adapter\ItemAdapter::buildQuery()
     */
    protected function filterItemsBySite(QueryBuilder $qb): void
    {
        if (!$this->resourceAdapter || !$this->site) {
            return;
        }

        $siteAlias = $this->resourceAdapter->createAlias();
        $qb->innerJoin(
            'omeka_root.sites', $siteAlias, 'WITH', $qb->expr()->eq(
                "$siteAlias.id",
                $this->resourceAdapter->createNamedParameter($qb, $this->site->id())
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
        if (!$this->resourceAdapter || !$this->site) {
            return;
        }

        $siteItemSetsAlias = $this->resourceAdapter->createAlias();
        $qb->innerJoin(
            'omeka_root.siteItemSets',
            $siteItemSetsAlias
        );
        $qb->andWhere($qb->expr()->eq(
            "$siteItemSetsAlias.site",
            $this->resourceAdapter->createNamedParameter($qb, $this->site->id()))
        );
        $qb->addOrderBy("$siteItemSetsAlias.position", 'ASC');
    }

    protected function previousMedia(MediaRepresentation $media): ?MediaRepresentation
    {
        /*
        $conn = $this->connection;
        $qb = $conn->createQueryBuilder()
            ->select('media.id')
            ->from('media', 'media')
            ->innerJoin('resource', 'resource')
            // TODO Manage the visibility.
            ->where('resource.is_public = 1')
            ->andWhere('media.position < :media_position')
            // TODO Get the media position.
            ->setParameter(':media_position', $media->position())
            ->andWhere('media.item_id = :item_id')
            ->setParameter(':item_id', $media->item()->id())
            ->orderBy('resource.id', 'ASC')
            ->setMaxResults(1);
        */

        // TODO Use a better way to get the previous media. Use positions?
        $previous = null;
        $mediaId = $media->id();
        foreach ($media->item()->media() as $media) {
            if ($media->id() === $mediaId) {
                return $previous;
            }
            $previous = $media;
        }
        return null;
    }

    protected function nextMedia(MediaRepresentation $media): ?MediaRepresentation
    {
        /*
        $conn = $this->connection;
        $qb = $conn->createQueryBuilder()
            ->select('media.id')
            ->from('media', 'media')
            ->innerJoin('resource', 'resource')
            // TODO Manage the visibility.
            ->where('resource.is_public = 1')
            ->andWhere('media.position > :media_position')
            // TODO Get the media position.
            ->setParameter(':media_position', $media->position())
            ->andWhere('media.item_id = :item_id')
            ->setParameter(':item_id', $media->item()->id())
            ->orderBy('resource.id', 'ASC')
            ->setMaxResults(1);
        */

        // TODO Use a better way to get the next media. Use positions?
        $next = false;
        $mediaId = $media->id();
        foreach ($media->item()->media() as $media) {
            if ($next) {
                return $media;
            }
            if ($media->id() === $mediaId) {
                $next = true;
            }
        }
        return null;
    }
}
