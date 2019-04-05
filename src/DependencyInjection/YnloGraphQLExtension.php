<?php

/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\DependencyInjection;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Ynlo\GraphQLBundle\Cache\DefinitionCacheWarmer;
use Ynlo\GraphQLBundle\Command\SubscriptionConsumerCommand;
use Ynlo\GraphQLBundle\Controller\GraphQLEndpointController;
use Ynlo\GraphQLBundle\Controller\SubscriptionsController;
use Ynlo\GraphQLBundle\Controller\SubscriptionsHeartbeatController;
use Ynlo\GraphQLBundle\Encoder\IDEncoderManager;
use Ynlo\GraphQLBundle\GraphiQL\JWTGraphiQLAuthentication;
use Ynlo\GraphQLBundle\GraphiQL\LexikJWTGraphiQLAuthenticator;
use Ynlo\GraphQLBundle\Request\SubscriptionsRequestMiddleware;
use Ynlo\GraphQLBundle\Subscription\Publisher;
use Ynlo\GraphQLBundle\Subscription\PubSub\RedisPubSubHandler;
use Ynlo\GraphQLBundle\Subscription\Subscriber;
use Ynlo\GraphQLBundle\Subscription\SubscriptionManager;

/**
 * Class YnloGraphQLExtension
 */
class YnloGraphQLExtension extends Extension
{
    protected $subscriptionsDependenciesInstalled = true;

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if (!isset($config['namespaces']['bundles']['aliases']['GraphQLBundle'])) {
            $config['namespaces']['bundles']['aliases']['GraphQLBundle'] = 'AppBundle';
        }

        $container->setParameter('graphql.config', $config);
        $container->setParameter('graphql.pagination', $config['pagination'] ?? []);
        $container->setParameter('graphql.error_handling', $config['error_handling'] ?? []);
        $container->setParameter('graphql.error_handling.controlled_errors', $config['error_handling']['controlled_errors'] ?? []);
        $container->setParameter('graphql.error_handling.jwt_auth_failure_compatibility', $config['error_handling']['jwt_auth_failure_compatibility'] ?? false);
        $container->setParameter('graphql.namespaces', $config['namespaces'] ?? []);
        $container->setParameter('graphql.cors_config', $config['cors'] ?? []);
        $container->setParameter('graphql.graphiql', $config['graphiql'] ?? []);
        $container->setParameter('graphql.graphiql_auth_jwt', $config['graphiql']['authentication']['provider']['jwt'] ?? []);//DEPRECATED
        $container->setParameter('graphql.graphiql_auth_lexik_jwt', $config['graphiql']['authentication']['provider']['lexik_jwt'] ?? []);
        $container->setParameter('graphql.security.validation_rules', $config['security']['validation_rules'] ?? []);
        $container->setParameter('graphql.subscriptions.redis', $config['subscriptions']['redis'] ?? []);
        $container->setParameter('graphql.subscriptions.ttl', $config['subscriptions']['ttl'] ?? []);

        $endpointsConfig = [];
        $endpointsConfig['endpoints'] = $config['endpoints'] ?? [];
        $endpointsConfig['default'] = $config['endpoint_default'] ?? null;
        $endpointsConfig['alias'] = $config['endpoint_alias'] ?? [];

        $container->setParameter('graphql.endpoints', $endpointsConfig);
        $container->setParameter('graphql.endpoints_list', array_keys($endpointsConfig['endpoints']));

        $graphiQLAuthProvider = null;

        //DEPRECATED since v1.1
        if ($config['graphiql']['authentication']['provider']['jwt']['enabled'] ?? false) {
            $graphiQLAuthProvider = JWTGraphiQLAuthentication::class;
            @trigger_error('The option `graphql.graphiql.authentication.provider.jwt` has been deprecated use `graphql.graphiql.authentication.provider.lexik_jwt` instead');
        }

        if ($config['graphiql']['authentication']['provider']['lexik_jwt']['enabled'] ?? false) {
            $graphiQLAuthProvider = LexikJWTGraphiQLAuthenticator::class;
            if (!interface_exists(JWTTokenManagerInterface::class)) {
                throw new \InvalidArgumentException('In order to use `lexik_jwt` authentication in GraphiQL Explorer must install LexikJWTAuthenticationBundle.');
            }
        }

        if ($config['graphiql']['authentication']['provider']['custom'] ?? false) {
            $graphiQLAuthProvider = $config['graphiql']['authentication']['provider']['custom'];
        }
        $container->setParameter('graphql.graphiql_auth_provider', $graphiQLAuthProvider);

        $configDir = __DIR__.'/../Resources/config';
        $loader = new YamlFileLoader($container, new FileLocator($configDir));
        $loader->load('services.yml');

        if ($container->getParameter('kernel.environment') !== 'dev') {
            $container->getDefinition(DefinitionCacheWarmer::class)->clearTag('kernel.event_subscriber');
        }

        //configure LexikJWTGraphiQLAuthenticator definition
        if ($config['graphiql']['authentication']['provider']['lexik_jwt']['enabled'] ?? false) {
            $providerName = sprintf('security.user.provider.concrete.%s', $config['graphiql']['authentication']['provider']['lexik_jwt']['user_provider']);
            $container->getDefinition(LexikJWTGraphiQLAuthenticator::class)
                      ->setArgument(1, new Reference($providerName));
        } else {
            $container->removeDefinition(LexikJWTGraphiQLAuthenticator::class);
        }

        $this->setBackwardCompatibilitySettings($container, $config['bc'] ?? []);

        //build the ID encoder manager with configured encoder
        $container->getDefinition(IDEncoderManager::class)
                  ->setPublic(true)
                  ->replaceArgument(0, new Reference($config['id_encoder']));


        //endpoint definition
        $container->getDefinition(GraphQLEndpointController::class)
                  ->addMethodCall('setErrorFormatter', [new Reference($config['error_handling']['formatter'])])
                  ->addMethodCall('setErrorHandler', [new Reference($config['error_handling']['handler'])]);

        $bundles = $container->getParameter('kernel.bundles');
        if (isset($bundles['MercureBundle'])) {
            $mercureHub = $config['subscriptions']['mercure_hub'];

            $mercurePublisherReference = new Reference(sprintf('mercure.hub.%s.publisher', $mercureHub));

            $container->getDefinition(SubscriptionManager::class)
                      ->addArgument(new Reference($config['subscriptions']['pubsub_handler']))
                      ->addArgument(new Parameter('kernel.secret'));

            $container->getDefinition(SubscriptionsController::class)
                      ->addArgument(new Reference($config['subscriptions']['pubsub_handler']))
                      ->addMethodCall('setMercureHubUrl', [new Parameter('mercure.hubs'), $mercureHub]);

            $container->getDefinition(SubscriptionsHeartbeatController::class)
                      ->addArgument(new Reference($config['subscriptions']['pubsub_handler']))
                      ->addMethodCall('setMercureHubUrl', [new Parameter('mercure.hubs'), $mercureHub]);

            $container->getDefinition(SubscriptionConsumerCommand::class)
                      ->addArgument($mercurePublisherReference);

            $container->getDefinition(GraphQLEndpointController::class)->addMethodCall('setPublisher', [$mercurePublisherReference]);
        } else {
            $container->removeDefinition(SubscriptionManager::class);
            $container->removeDefinition(SubscriptionConsumerCommand::class);
            $container->removeDefinition(SubscriptionsController::class);
            $container->removeDefinition(SubscriptionsHeartbeatController::class);
            $container->removeDefinition(SubscriptionsRequestMiddleware::class);
            $container->removeDefinition(Subscriber::class);
            $container->removeDefinition(Publisher::class);
            $container->removeDefinition(RedisPubSubHandler::class);
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     *
     * @throws \ReflectionException
     */
    public function setBackwardCompatibilitySettings(ContainerBuilder $container, array $config): void
    {
        foreach ($container->getDefinitions() as $class => $definition) {
            if ($definition->getClass()) {
                $class = $definition->getClass();
            }
            if (class_exists($class)) {
                $ref = new \ReflectionClass($class);
                if ($ref->implementsInterface(BackwardCompatibilityAwareInterface::class)) {
                    $definition->addMethodCall('setBCConfig', [$config]);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAlias()
    {
        return 'graphql';
    }
}
