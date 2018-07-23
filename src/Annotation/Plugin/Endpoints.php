<?php

/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\Annotation\Plugin;

/**
 * @Annotation
 */
class Endpoints extends PluginConfigAnnotation
{
    /**
     * @var array
     */
    public $endpoints = [];
}
