<?php
/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\Mutation;

use Ynlo\GraphQLBundle\Error\NodeNotFoundException;
use Ynlo\GraphQLBundle\Model\NodeInterface;
use Ynlo\GraphQLBundle\Model\UpdateNodePayload;

/**
 * Class UpdateNodeMutation
 */
class UpdateNodeMutation extends AbstractMutationResolver
{
    /**
     * {@inheritdoc}
     */
    protected function process($data)
    {
        $this->preUpdate($data);
        $this->getManager()->flush();
        $this->postUpdate($data);
    }

    /**
     * {@inheritdoc}
     */
    protected function postFormSubmit($inputSource, $submittedData)
    {
        if ($submittedData instanceof NodeInterface && $submittedData->getId()) {
            return;
        }

        throw new NodeNotFoundException();
    }

    /**
     * {@inheritdoc}
     */
    protected function returnPayload($data, $violations, $inputSource)
    {
        if (count($violations)) {
            $data = null;
        }

        return new UpdateNodePayload($data, $violations, $inputSource['clientMutationId'] ?? null);
    }

    /**
     * @param NodeInterface $node
     */
    protected function preUpdate(NodeInterface $node)
    {
        //override
    }

    /**
     * @param NodeInterface $node
     */
    protected function postUpdate(NodeInterface $node)
    {
        //override
    }
}
