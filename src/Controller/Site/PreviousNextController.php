<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Site;

use EasyAdmin\View\Helper\PreviousNext;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Render the previous/next navigation for the query kept by the browser.
 *
 * The query is sent by the script browse-context.js, so no session is used.
 * The neighbors are searched with the visibility of the current user.
 */
class PreviousNextController extends AbstractActionController
{
    /**
     * Templates that the browser may request.
     */
    const TEMPLATES = [
        PreviousNext::PARTIAL_NAME,
        'common/resource-page-block-layout/previous-next',
    ];

    public function indexAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/html; charset=utf-8')
            ->addHeaderLine('X-Robots-Tag', 'noindex, nofollow');

        $params = $this->params();
        try {
            $resource = $this->api()
                ->read($params->fromRoute('resource-name'), (int) $params->fromRoute('id'))
                ->getContent();
        } catch (\Throwable $e) {
            return $response;
        }

        $query = json_decode((string) $params->fromQuery('query'), true);
        $options = json_decode((string) $params->fromQuery('options'), true);
        $options = is_array($options) ? $options : [];
        $url = (string) $params->fromQuery('url');
        $isLocalUrl = $url !== '' && $url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/');

        $viewHelpers = $this->getEvent()->getApplication()->getServiceManager()->get('ViewHelperManager');
        $response->setContent($viewHelpers->get('previousNext')($resource, [
            'source_query' => 'browser',
            'query' => is_array($query) ? $query : [],
            'template' => in_array($options['template'] ?? null, self::TEMPLATES, true) ? $options['template'] : null,
            'back' => !empty($options['back']),
            'back_url' => $isLocalUrl ? $url : null,
        ]));
        return $response;
    }
}
