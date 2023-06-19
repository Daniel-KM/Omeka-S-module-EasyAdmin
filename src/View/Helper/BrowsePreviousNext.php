<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use AdvancedSearch\Mvc\Controller\Plugin\SearchResources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Laminas\EventManager\Event;
use Laminas\Session\Container;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Adapter\Manager as ApiAdapterManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Request;

class BrowsePreviousNext extends AbstractHelper
{
    /**
     * @var ApiAdapterManager
     */
    protected $apiAdapterManager;

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

    public function __construct(
        ApiAdapterManager $apiAdapterManager,
        Connection $connection,
        EntityManager $entityManager,
        ?SearchResources $searchResources
    ) {
        $this->apiAdapterManager = $apiAdapterManager;
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        $this->searchResources = $searchResources;
    }

    /**
     * Get the links to previous, next and back of a resource.
     *
     * @todo Check visibility for public front-end.
     *
     * @return string Html code
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource, array $options = []): string
    {
        $view = $this->getView();

        // FIXME Fix the query below with @rownum on mysql (works on mariadb).
        if ($view->setting('next_prevnext_disable')) {
            return '';
        }

        $params = $view->params();
        $isAdmin = (bool) $params->fromRoute('__ADMIN__');
        $ui = $isAdmin ? 'admin' : 'public';

        $options += [
            'template' => null,
            'upper' => true,
        ];

        $session = new Container('Next');
        $query = $session->lastQuery[$ui] ?? [];

        $lastBrowse = $options['upper'] ? $view->lastBrowsePage() : null;
        [$previous, $next] = $this->previousNext($resource, $query);

        $template = empty($options['template']) ? 'common/browse-previous-next' : $options['template'];
        unset($options['template']);

        return $view->partial($template, [
            'resource' => $resource,
            'previous' => $previous,
            'next' => $next,
            'lastBrowse' => $lastBrowse,
            'options' => $options,
        ]);
    }

    protected function previousNext(AbstractResourceEntityRepresentation $resource, array $query): array
    {
        $resourceName = $resource->resourceName();

        // In mysql 8, row_number() can be used, not in the default Omeka (5.5.3).

        // A simple solution:
        // The position is useless, we just want to get the previous row:
        // so get field used to order by, then get the value of the resource for
        // this field, then offset -1 and limit 1.
        // This solution is simpler, but manage only the case where there is a
        // simple order, so no order by title, count, etc.

        // So use a full query.

        // TODO Improve the way to get the previous and next resources.
        // But the original query has parameters that should be set in the sql,
        // but the parameters have placeholders in dql, but only "?" in pdo sql
        // (see \Doctrine\ORM\Query\ParserResult::getParameterMappings()),
        // and there seems to be no simple way to get the raw sql with
        // placeholders, or the full list of parameters.
        // So the params are set inside the query, then the position can be
        // retrieved.

        // First step, get the original query, unchanged, without limit.
        $qb = $this->prepareSearch($resourceName, $query)
            ->setMaxResults(null)
            ->setFirstResult(null);

        // Manage visibility.
        // TODO Use standard automatic filter to check visibility.

        // Convert query builder parameters into standard pdo parameters.
        $parameters = [];

        $quote = function ($v) {
            $v = is_object($v) ? (string) $v->getId() : (string) $v;
            if (strpos($v, "'")) {
                // Direct sql quotation uses two single quotes, not a backslash.
                $v = $this->connection->quote($v);
                return str_replace("\'", "''", $v);
            }
            return $this->connection->quote($v);
        };

        foreach ($qb->getParameters()->toArray() as $param) {
            $paramValue = $param->getValue();
            if (is_array($paramValue)) {
                $paramValue = implode(',', array_map($quote, $paramValue));
            } elseif ($param->getType() !== \Doctrine\DBAL\ParameterType::INTEGER) {
                // TODO Check \Doctrine\DBAL\ParameterType::INTEGER or \Doctrine\DBAL\Types\Types::INTEGER.
                $paramValue = $quote($paramValue);
            }
            $parameters[':' . $param->getName()] = $paramValue;
        }

        // Replace placeholders by true values.
        $dql = $qb->getDQL();
        $dql = str_replace(array_keys($parameters), array_values($parameters), $dql);

        // Convert dql into sql.
        $sqlQuery = $this->entityManager->createQuery($dql)->getSQL();
        $sql = <<<SQL
SELECT y.position
FROM (
    SELECT x.id_0 AS id, @rownum := @rownum + 1 AS position
    FROM (
        $sqlQuery
    ) AS x
    JOIN (SELECT @rownum := 0) AS r
) AS y
WHERE y.id = ?
LIMIT 1;
SQL;
        $position = $this->connection->executeQuery($sql, [$resource->id()])->fetchOne();

        // Second step, get the previous and next resources.
        if (!$position) {
            return [null, null];
        }

        $sql = <<<SQL
SELECT y.position, y.id
FROM (
    SELECT x.id_0 AS id, @rownum := @rownum + 1 AS position
    FROM (
        $sqlQuery
    ) AS x
    JOIN (SELECT @rownum := 0) AS r
) AS y
WHERE y.position >= ? AND y.position <= ?
LIMIT 3;
SQL;
        $previous = $position - 1;
        $next = $position + 1;
        $ids = $this->connection->executeQuery($sql, [$previous, $next])->fetchAllKeyValue();

        $api = $this->getView()->api();
        $previousResource = null;
        if (isset($ids[$previous])) {
            try {
                $previousResource = $api->read($resourceName, $ids[$previous])->getContent();
            } catch (\Exception $e) {
            }
        }
        $nextResource = null;
        if (isset($ids[$next])) {
            try {
                $nextResource = $api->read($resourceName, $ids[$next])->getContent();
            } catch (\Exception $e) {
            }
        }
        return [$previousResource, $nextResource];
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
        $adapter = $this->apiAdapterManager->get($resourceName);

        $request = new Request('search', $resourceName);

        // Use specific module Advanced Search adapter if available.
        $override = [];
        if ($this->searchResources) {
            $this->searchResources->setAdapter($adapter);
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
        $entityClass = $adapter->getEntityClass();

        // $adapter->index = 0;
        $qb = $adapter->getEntityManager()
            ->createQueryBuilder()
            ->select('omeka_root')
            ->from($entityClass, 'omeka_root');
        $adapter->buildBaseQuery($qb, $query);
        $adapter->buildQuery($qb, $query);
        $qb->groupBy('omeka_root.id');

        // Trigger the search.query event.
        $event = new Event('api.search.query', $adapter, [
            'queryBuilder' => $qb,
            'request' => $request,
        ]);
        $adapter->getEventManager()->triggerEvent($event);

        // Finish building the search query. In addition to any sorting the
        // adapters add, always sort by entity ID.
        $adapter->sortQuery($qb, $query);
        $adapter->limitQuery($qb, $query);
        $qb->addOrderBy('omeka_root.id', $query['sort_order']);

        return $qb;
    }
}
