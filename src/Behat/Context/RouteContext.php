<?php
/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Ynlo\GraphQLBundle\Behat\Client\ClientAwareInterface;
use Ynlo\GraphQLBundle\Behat\Client\ClientAwareTrait;
use Ynlo\GraphQLBundle\Behat\GraphQLApiExtension;
use Ynlo\GraphQLBundle\Behat\Kernel\KernelAwareInterface;
use Ynlo\GraphQLBundle\Behat\Kernel\KernelAwareTrait;

final class RouteContext implements Context, KernelAwareInterface, ClientAwareInterface
{
    use ClientAwareTrait;
    use KernelAwareTrait;

    /**
     * @BeforeStep
     */
    public function beforeStep(BeforeStepScope $scope)
    {
        $config = GraphQLApiExtension::getConfig();

        $tags = $scope->getFeature()->getTags();
        $featureRoute = null;
        foreach ($tags as $tag) {
            if (preg_match('/^route:/', $tag)) {
                $featureRoute = preg_replace('/^route:/', null, $tag);
                break;
            }
        }
        if (!$featureRoute && isset($config['route'])) {
            $featureRoute = $config['route'];
        }

        if ($featureRoute) {
            $endpoint = $this->kernel->getContainer()->get('router')->generate($featureRoute);
            $this->client->setEndpoint($endpoint);
        }
    }
}
