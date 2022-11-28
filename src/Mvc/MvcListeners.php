<?php declare(strict_types=1);

namespace EasyAdmin\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;

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
        $response = $event->getResponse();
        $routeMatch = $event->getRouteMatch();
        $matchedRouteName = $routeMatch->getMatchedRouteName();

        if (in_array($matchedRouteName, ['maintenance', 'migrate', 'install'])) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');

        $isMaintenance = (bool) $settings->get('easyadmin_maintenance_status');
        if (!$isMaintenance) {
            return;
        }

        if ($routeMatch->getParam('__ADMIN__')) {
            return;
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
