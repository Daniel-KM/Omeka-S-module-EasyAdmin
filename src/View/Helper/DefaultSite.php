<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Get the configured default site, or the first public site, or the first site.
 */
class DefaultSite extends AbstractHelper
{
    /**
     * @var ?\Omeka\Api\Representation\SiteRepresentation
     */
    protected $defaultSite = null;

    /**
     * @var ?string
     */
    protected $defaultSiteId = null;

    /**
     * @var ?string
     */
    protected $defaultSiteSlug = null;

    public function __construct(?int $defaultSiteId, ?string $defaultSiteSlug)
    {
        // The site is loaded only when needed, because this helper is mainly
        // designed to get the slug or the id quickly.
        $this->defaultSiteId = $defaultSiteId;
        $this->defaultSiteSlug = $defaultSiteSlug;
    }

    public function __invoke(?string $metadata = null)
    {
        if ($metadata === 'slug') {
            return $this->defaultSiteSlug;
        } elseif ($metadata === 'id') {
            return $this->defaultSiteId;
        } elseif ($metadata === 'id_slug') {
            return $this->defaultSiteId
                ? [$this->defaultSiteId => $this->defaultSiteSlug]
                : [];
        } elseif ($metadata === 'slug_id') {
            return $this->defaultSiteId
                ? [$this->defaultSiteSlug => $this->defaultSiteId]
                : [];
        }

        if (is_null($metadata) && $this->defaultSiteId) {
            // Manage private site issue.
            try {
                return $this->getView()->api()->read('sites', ['id' => $this->defaultSiteId])->getContent();
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
