<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\Session\Container;
use Laminas\View\Helper\AbstractHelper;

class LastBrowsePage extends AbstractHelper
{
    /**
     * Get the last browse page url (browse or search with the user query).
     *
     * It allows to go back to the last search result page after browsing.
     *
     * On a site, the last browse page is kept by the browser, so no session is
     * created for the visitors: the default url is returned and the links with
     * the attribute "data-browse-back" are updated by the script
     * browse-context.js.
     *
     * @param string|null $default The default page to go back. If not set,
     * go to the resource browse page.
     */
    public function __invoke(?string $default = null): string
    {
        $view = $this->getView();
        $isAdmin = $view->status()->isAdminRequest();
        $plugins = $view->getHelperPluginManager();

        if (!$isAdmin) {
            $view->headScript()->appendFile($view->assetUrl('js/browse-context.js', 'EasyAdmin'));
            if ($default) {
                return $default;
            }
            return $plugins->has('searchingUrl')
                ? $plugins->get('searchingUrl')(false, [])
                : $view->url('site/resource', ['action' => ''], [], true);
        }

        $session = new Container('EasyAdmin');
        if (empty($session->lastBrowsePage['admin']['items'])) {
            if ($default) {
                return $default;
            }
            return $plugins->has('searchingUrl')
                ? $plugins->get('searchingUrl')(false, $session->lastQuery['admin']['items'] ?? [])
                : $view->url('admin/default', ['action' => ''], [], true);
        }
        // The stored url may contain expired csrf tokens, but this is harmless
        // for navigation purposes: forms will regenerate new tokens as needed.
        // So, to remove any csrf key is useless for a search page.
        return $session->lastBrowsePage['admin']['items'];
    }
}
