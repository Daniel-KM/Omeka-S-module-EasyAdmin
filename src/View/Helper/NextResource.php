<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class NextResource extends AbstractHelper
{
    use PreviousNextResourceTrait;

    /**
     * Get the public resource immediately following the current one.
     *
     * @param string $sourceQuery "session" (default when no query), "setting"
     *   else passed query.
     * @param array|string $query
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource, ?string $sourceQuery = null, $query = null): ?AbstractResourceEntityRepresentation
    {
        $resourceName = $resource->resourceName();

        // TODO Manage query for media.
        // TODO Manage different queries by resource type.
        if ($resourceName === 'media') {
            return $this->nextMedia($resource);
        }

        if (empty($sourceQuery)) {
            $sourceQuery = empty($query) ? 'session' : null;
        }

        $view = $this->getView();
        $query = $this->getQuery($resourceName, $sourceQuery, $query);

        $next = $this->getPreviousAndNextResourceIds($resource, $query)[1];
        return $next
            ? $view->api()->read($resourceName, ['id' => $next])->getContent()
            : null;
    }
}
