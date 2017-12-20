<?php
/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Class CorsListener
 */
class CorsListener implements EventSubscriberInterface
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var boolean
     */
    protected $enabled;

    /**
     * @param array $config
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? false;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'kernel.request' => 'onKernelRequest',
            'kernel.response' => 'onKernelResponse',
        ];
    }

    /**
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

        if ($this->enabled && 'OPTIONS' === $event->getRequest()->getRealMethod()) {
            $response = new Response();
            $this->setHeaders($response);
            $event->setResponse($response);
        }
    }

    /**
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        if ($this->enabled) {
            $response = $event->getResponse();
            $this->setHeaders($response);
            $event->setResponse($response);
        }
    }

    /**
     * @param Response $response
     */
    protected function setHeaders(Response $response)
    {
        $headers = $this->config['allow_headers'] ?? [];
        if (is_array($headers)) {
            $headers = implode(', ', $headers);
        }

        $methods = $this->config['allow_methods'] ?? [];
        if (is_array($methods)) {
            $methods = implode(', ', $methods);
        }

        $origins = $this->config['allow_origins'] ?? [];
        if (is_array($origins)) {
            $origins = implode(', ', $origins);
        }

        $response->headers->set('Access-Control-Allow-Credentials', $this->config['allow_credentials'] ?? false);
        $response->headers->set('Access-Control-Allow-Headers', $headers);
        $response->headers->set('Access-Control-Allow-Origin', $origins);
        $response->headers->set('Access-Control-Allow-Methods', $methods);
        $response->headers->set('Vary', 'Origin');
    }
}
