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
     * Visibility is handled automatically:
     * - On public site: only public resources (or owned by current user) are shown
     * - On admin: all resources are shown (respecting acl permissions)
     *
     * @param array $options
     * - template (string): set specific template (default: common/previous-next)
     * - back (bool): add back link (upper page or home page)
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
        $options['query'] ??= null;
        $asArray = !empty($options['as_array']);
        unset($options['as_array']);

        $query = $this->getQuery($resourceName, $options['source_query'], $options['query']);

        if (!empty($options['back_url'])) {
            $lastBrowseUrl = $options['back_url'];
        } elseif ($options['source_query'] === 'session') {
            $lastBrowseUrl = $options['back'] || $asArray ? $view->lastBrowsePage() : null;
        } else {
            $lastBrowseUrl = $options['back'] || $asArray ? $view->url($this->site ? 'site/resource' : 'admin/default', ['action' => ''], [], true) : null;
        }

        // Media navigation is within an item (prev/next media of same item).
        // Custom queries for media filtering could be added if needed.
        if ($resourceName === 'media') {
            $previous = $this->previousMedia($resource);
            $next = $this->nextMedia($resource);
        } else {
            [$previous, $next] = $this->getPreviousAndNextResourceIds($resource, $query);
            $previous = $previous
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

        $html = $options['template'] !== self::PARTIAL_NAME && $view->resolver($options['template'])
            ? $view->partial($options['template'], $vars)
            : $view->partial(self::PARTIAL_NAME, $vars);

        // On a site, the last query is kept by the browser, so the navigation
        // is replaced via the endpoint when the browser has a context.
        if (!$this->site || $options['source_query'] !== 'session' || $resourceName === 'media') {
            return $html;
        }

        // The prefix of the site is forced, because the url helper of the
        // module CleanUrl removes it for the main site, and the route requires
        // it.
        $endpoint = $view->basePath('s/' . rawurlencode($this->site->slug()) . '/previous-next/' . $resourceName . '/' . $resource->id());
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $view->headScript()->appendFile($view->assetUrl('js/browse-context.js', 'EasyAdmin'));
        return sprintf(
            '<div class="previous-next-browse-context" data-browse-context-endpoint="%1$s" data-browse-context-require="query" data-browse-context-options="%2$s" data-site="%3$s" data-resource-name="%4$s" data-resource-id="%5$s">%6$s</div>',
            $escapeAttr($endpoint),
            $escapeAttr(json_encode(['template' => $options['template'], 'back' => $options['back']])),
            $escapeAttr($this->site->slug()),
            $escapeAttr($resourceName),
            $resource->id(),
            $html
        );
    }
}
