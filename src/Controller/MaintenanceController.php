<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

use Laminas\View\Model\ViewModel;

class MaintenanceController extends \Omeka\Controller\MaintenanceController
{
    /**
     * Default delay sent in the header "Retry-After", in seconds.
     */
    const RETRY_AFTER = 3600;

    public function indexAction()
    {
        $status = $this->status();
        $settings = $this->settings();
        // Don't display the maintenance page when the site is on.
        if (!$settings->get('easyadmin_maintenance_mode')
            // Except if the site is under maintenance of course. See
            // Omeka\Mvc\MvcListeners::redirectToMigration().
            && !$status->needsVersionUpdate()
            && !$status->needsMigration()
        ) {
            return $this->redirect()->toRoute('top');
        }

        // The status 503 tells the clients and the search engines that the
        // unavailability is temporary, so the indexed pages are kept.
        $response = $this->getResponse();
        $response->setStatusCode(503);
        $response->getHeaders()
            ->addHeaderLine('Retry-After', (string) self::RETRY_AFTER)
            ->addHeaderLine('X-Robots-Tag', 'noindex');

        $view = new ViewModel([
            'text' => $settings->get('easyadmin_maintenance_text'),
        ]);
        return $view
            ->setTemplate('omeka/maintenance/index');
    }
}
