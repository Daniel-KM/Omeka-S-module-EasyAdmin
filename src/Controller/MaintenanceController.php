<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

use Laminas\View\Model\ViewModel;

class MaintenanceController extends \Omeka\Controller\MaintenanceController
{
    public function indexAction()
    {
        $status = $this->status();
        $settings = $this->settings();
        // Don't display the maintenance page when the site is on.
        if (!$settings->get('easyadmin_maintenance_mode')
            // Except if the site is under maintenance of course.
            // See Omeka\Mvc\MvcListeners::redirectToMigration().
            && !$status->needsVersionUpdate()
            && !$status->needsMigration()
        ) {
            return $this->redirect()->toRoute('top');
        }
        $view = new ViewModel([
            'text' => $settings->get('easyadmin_maintenance_text'),
        ]);
        return $view
            ->setTemplate('omeka/maintenance/index');
    }
}
