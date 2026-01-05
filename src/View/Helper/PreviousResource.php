<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class PreviousResource extends AbstractHelper
{
    use PreviousNextResourceTrait;

    /**
     * Get the resource immediately before the current one.
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
            return $this->previousMedia($resource);
        }

        if (empty($sourceQuery)) {
            $sourceQuery = empty($query) ? 'session' : null;
        }

        $view = $this->getView();
        $query = $this->getQuery($resourceName, $sourceQuery, $query);

        $previous = $this->getPreviousAndNextResourceIds($resource, $query)[0];
        return $previous
            ? $view->api()->read($resourceName, ['id' => $previous])->getContent()
            : null;
    }
}
