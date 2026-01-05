<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class NextResource extends AbstractHelper
{
    use PreviousNextResourceTrait;

    /**
     * Get the resource immediately following the current one.
     *
     * Visibility is handled automatically:
     * - On public site: only public resources (or owned by current user) are shown
     * - On admin: all resources are shown (respecting acl permissions)
     *
     * @param string $sourceQuery "session" (default when no query), "setting"
     *   else passed query.
     * @param array|string $query
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource, ?string $sourceQuery = null, $query = null): ?AbstractResourceEntityRepresentation
    {
        $resourceName = $resource->resourceName();

        // Media navigation is within an item (prev/next media of same item).
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
