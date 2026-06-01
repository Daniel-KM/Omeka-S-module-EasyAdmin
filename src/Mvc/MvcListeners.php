<?php declare(strict_types=1);

namespace EasyAdmin\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Permissions\Acl;

class MvcListeners extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectToMaintenance'],
            -100
        );
    }

    /**
     * Redirect requests when wanted.
     *
     * The Omeka checks are already done.
     *
     * Supported modes:
     * - 'anonymous': block the public front-end for anonymous visitors only;
     *   authenticated users (admins included) can still browse sites.
     * - 'admin': block the public front-end for everyone except global admins
     *   (the admin back-end stays open for authorized users).
     * - 'public': block the public front-end for everyone (the admin back-end
     *   stays open for authorized users).
     * - 'backend': block the admin back-end for every user except global
     *   admins (the public front-end remains open).
     * - 'lockdown': block both the public front-end and the admin back-end
     *   for everyone except global admins (full lockdown).
     */
    public function redirectToMaintenance(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return;
        }
        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if (in_array($matchedRouteName, [
            'maintenance',
            'migrate',
            'install',
            'login',
            'logout',
        ])) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');

        $maintenanceMode = $settings->get('easyadmin_maintenance_mode');
        if (!$maintenanceMode) {
            return;
        }

        $isAdminRoute = (bool) $routeMatch->getParam('__ADMIN__');

        if ($maintenanceMode === 'public') {
            if ($isAdminRoute) {
                return;
            }
        } elseif ($maintenanceMode === 'anonymous') {
            if ($isAdminRoute) {
                return;
            }
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if ($user) {
                return;
            }
        } elseif ($maintenanceMode === 'backend') {
            if (!$isAdminRoute) {
                return;
            }
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if ($user && $user->getRole() === Acl::ROLE_GLOBAL_ADMIN) {
                return;
            }
        } elseif ($maintenanceMode === 'admin') {
            if ($isAdminRoute) {
                return;
            }
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if ($user && $user->getRole() === Acl::ROLE_GLOBAL_ADMIN) {
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
