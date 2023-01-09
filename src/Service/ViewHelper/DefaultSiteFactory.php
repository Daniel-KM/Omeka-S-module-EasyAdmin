<?php declare(strict_types=1);

namespace EasyAdmin\Service\ViewHelper;

use EasyAdmin\View\Helper\DefaultSite;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory to get default site, or the first public, or the first one.
 */
class DefaultSiteFactory implements FactoryInterface
{
    /**
     * Create and return the DefaultSite view helper.
     *
     * @return DefaultSite
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Use search, because read() returns the whole site representation, but
        // only the slug is needed in most of the cases, so it's quicker.
        $api = $services->get('Omeka\ApiManager');
        $defaultSiteId = $services->get('Omeka\Settings')->get('default_site');
        if ($defaultSiteId) {
            $slugs = $api->search('sites', ['id' => $defaultSiteId, 'limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
            [$id, $slug] = $slugs ? [(int) key($slugs), reset($slugs)] : [null, null];
        }
        // Fix issues after Omeka install without public site, so very rarely.
        if (empty($id)) {
            $slugs = $api->search('sites', ['is_public' => true, 'limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
            [$id, $slug] = $slugs ? [(int) key($slugs), reset($slugs)] : [null, null];
            if (empty($id)) {
                $slugs = $api->search('sites', ['limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
                [$id, $slug] = $slugs ? [(int) key($slugs), reset($slugs)] : [null, null];
            }
        }
        return new DefaultSite($id, $slug);
    }
}
