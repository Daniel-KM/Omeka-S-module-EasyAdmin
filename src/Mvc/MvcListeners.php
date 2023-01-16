<?php declare(strict_types=1);

namespace EasyAdmin\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Permissions\Acl;

class MvcListeners extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectToMaintenance']
        );
    }

    /**
     * Redirect requests when wanted.
     *
     * The Omeka checks are already done.
     */
    public function redirectToMaintenance(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if (in_array($matchedRouteName, [
            'maintenance',
            'migrate',
            'install',
            'login',
        ])) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');

        $maintenanceMode = $settings->get('easyadmin_maintenance_mode');
        if (!$maintenanceMode) {
            return;
        }

        if ($maintenanceMode === 'public') {
            if ($routeMatch->getParam('__ADMIN__')) {
                return;
            }
        } else {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if ($user && $user->getRole() === Acl::ROLE_GLOBAL_ADMIN) {
                return;
            }
        }

        $url = $event->getRouter()->assemble([], ['name' => 'maintenance']);

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $url);
        return $response
            ->setStatusCode(302)
            ->sendHeaders();
    }
}
