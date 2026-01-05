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
     * @param string|null $default The default page to go back. If not set,
     * go to the resource browse page.
     */
    public function __invoke(?string $default = null): string
    {
        $view = $this->getView();
        $isAdmin = $view->status()->isAdminRequest();
        $ui = $isAdmin ? 'admin' : 'public';
        $session = new Container('EasyAdmin');
        if (empty($session->lastBrowsePage[$ui]['items'])) {
            if ($default) {
                return $default;
            }
            $plugins = $view->getHelperPluginManager();
            return $plugins->has('searchingUrl')
                ? $plugins->get('searchingUrl')(false, $session->lastQuery[$ui]['items'] ?? [])
                : $view->url($isAdmin ? 'admin/default' : 'site/resource', ['action' => ''], [], true);
        }
        // The stored url may contain expired csrf tokens, but this is harmless
        // for navigation purposes: forms will regenerate new tokens as needed.
        // So, to remove any csrf key is useless for a search page.
        return $session->lastBrowsePage[$ui]['items'];
    }
}
