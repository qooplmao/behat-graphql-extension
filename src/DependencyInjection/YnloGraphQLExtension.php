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

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Ynlo\GraphQLBundle\Cache\DefinitionCacheWarmer;
use Ynlo\GraphQLBundle\Controller\GraphQLEndpointController;
use Ynlo\GraphQLBundle\Encoder\IDEncoderManager;
use Ynlo\GraphQLBundle\GraphiQL\JWTGraphiQLAuthentication;

/**
 * Class YnloGraphQLExtension
 */
class YnloGraphQLExtension extends Extension
{
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
        $container->setParameter('graphql.graphiql_auth_jwt', $config['graphiql']['authentication']['provider']['jwt'] ?? []);
        $container->setParameter('graphql.security.validation_rules', $config['security']['validation_rules'] ?? []);

        $endpointsConfig = [];
        $endpointsConfig['endpoints'] = $config['endpoints'] ?? [];
        $endpointsConfig['default'] = $config['endpoint_default'] ?? null;
        $endpointsConfig['alias'] = $config['endpoint_alias'] ?? [];

        $container->setParameter('graphql.endpoints', $endpointsConfig);
        $container->setParameter('graphql.endpoints_list', array_keys($endpointsConfig['endpoints']));

        $graphiQLAuthProvider = null;
        if ($config['graphiql']['authentication']['provider']['jwt']['enabled'] ?? false) {
            $graphiQLAuthProvider = JWTGraphiQLAuthentication::class;
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

        //build the ID encoder manager with configured encoder
        $container->getDefinition(IDEncoderManager::class)
                  ->setPublic(true)
                  ->replaceArgument(0, $container->getDefinition($config['id_encoder']));


        //endpoint definition
        $container->getDefinition(GraphQLEndpointController::class)
                  ->addMethodCall('setErrorFormatter', [$container->getDefinition($config['error_handling']['formatter'])])
                  ->addMethodCall('setErrorHandler', [$container->getDefinition($config['error_handling']['handler'])]);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlias()
    {
        return 'graphql';
    }
}
