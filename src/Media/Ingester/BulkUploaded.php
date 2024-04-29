<?php declare(strict_types=1);

namespace EasyAdmin\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * This ingester is used with ingester "bulk_upload" in order to avoid to
 * process bulk uploaded files multiple times. In fact, it only avoids to
 * display "unknown ingester" in admin interface.
 */
class BulkUploaded implements IngesterInterface
{
    public function getLabel()
    {
        return 'Bulk uploaded'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return '';
    }
}
