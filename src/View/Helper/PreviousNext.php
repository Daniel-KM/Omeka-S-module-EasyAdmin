<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class PreviousNext extends AbstractHelper
{
    use PreviousNextResourceTrait;

    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/previous-next';

    /**
     * Output the links to previous, next and back of a resource.
     *
     * @todo Check visibility for public front-end.
     *
     * @param array $options
     * - template (string): set specific template (default: common/previous-next)
     * - back (bool): add back link
     * - source_query (string): "session" (default when no query), "setting" else
     *   passed query.
     * - query (array|string): use a specific query
     * - as_array (bool): return data as an array
     * @return string|array Html code or resources.
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource, array $options = [])
    {
        $view = $this->getView();

        $resourceName = $resource->resourceName();

        $options['template'] = empty($options['template']) ? self::PARTIAL_NAME : $options['template'];
        $options['back'] = !empty($options['back']);
        $options['source_query'] = $options['source_query']
            ?? (empty($options['query']) ? 'session' : null);
        $options['query'] = $options['query'] ?? null;
        $asArray = !empty($options['as_array']);
        unset($options['as_array']);

        $query = $this->getQuery($resourceName, $options['source_query'], $options['query']);

        if ($options['source_query'] === 'session') {
            $lastBrowseUrl = $options['back'] || $asArray? $view->lastBrowsePage() : null;
        } else {
            $lastBrowseUrl = $options['back'] || $asArray? $view->url($this->site ? 'site/resource' : 'admin/default', ['action' => ''], [], true) : null;
        }

        // TODO Manage query for media.
        // TODO Manage different queries by resource type.
        $resourceName = $resource->resourceName();
        if ($resourceName === 'media') {
            $previous = $this->previousMedia($resource);
            $next = $this->nextMedia($resource);
        } else {
            [$previous, $next] = $this->getPreviousAndNextResourceIds($resource, $query);
            $previous =  $previous
                ? $view->api()->read($resourceName, ['id' => $previous])->getContent()
                : null;
            $next = $next
                ? $view->api()->read($resourceName, ['id' => $next])->getContent()
                : null;
        }

        $vars = [
            'resource' => $resource,
            'previous' => $previous,
            'next' => $next,
            'lastBrowseUrl' => $lastBrowseUrl,
        ];

        if ($asArray) {
            return $vars;
        }

        $vars['back'] = $options['back'];
        $vars['options'] = $options;

        return $options['template'] !== self::PARTIAL_NAME && $view->resolver($options['template'])
            ? $view->partial($options['template'], $vars)
            : $view->partial(self::PARTIAL_NAME, $vars);
    }
}
