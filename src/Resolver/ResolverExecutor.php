<?php
/*******************************************************************************
 *  This file is part of the GraphQL Bundle package.
 *
 *  (c) YnloUltratech <support@ynloultratech.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 ******************************************************************************/

namespace Ynlo\GraphQLBundle\Resolver;

use Doctrine\Common\Util\ClassUtils;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Ynlo\GraphQLBundle\Definition\FieldDefinition;
use Ynlo\GraphQLBundle\Definition\ObjectDefinitionInterface;
use Ynlo\GraphQLBundle\Definition\QueryDefinition;
use Ynlo\GraphQLBundle\Definition\Registry\DefinitionManager;
use Ynlo\GraphQLBundle\Model\ID;

/**
 * This resolver act as a middleware between the query and final resolvers.
 * Using injection of parameters can resolve the parameters needed by the final resolver before invoke
 */
class ResolverExecutor implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var QueryDefinition
     */
    protected $query;

    /**
     * @var DefinitionManager
     */
    protected $manager;

    /**
     * @var mixed
     */
    protected $root;

    /**
     * @var ResolveInfo
     */
    protected $resolveInfo;

    /**
     * @var mixed
     */
    protected $context;

    /**
     * @var array
     */
    protected $args = [];

    /**
     * @param ContainerInterface $container
     * @param DefinitionManager  $manager
     * @param QueryDefinition    $query
     */
    public function __construct(ContainerInterface $container, DefinitionManager $manager, QueryDefinition $query)
    {
        $this->query = $query;
        $this->manager = $manager;
        $this->container = $container;
    }

    /**
     * @param mixed       $root
     * @param array       $args
     * @param mixed       $context
     * @param ResolveInfo $resolveInfo
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function __invoke($root, array $args, $context, ResolveInfo $resolveInfo)
    {
        $this->root = $root;
        $this->args = $args;
        $this->context = $context;
        $this->resolveInfo = $resolveInfo;

        $resolverName = $this->query->getResolver();

        $resolver = null;
        $refMethod = null;

        if (class_exists($resolverName)) {
            $refClass = new \ReflectionClass($resolverName);

            /** @var callable $resolver */
            $resolver = $refClass->newInstance();
            if ($resolver instanceof ContainerAwareInterface) {
                $resolver->setContainer($this->container);
            }
            if ($refClass->hasMethod('__invoke')) {
                $refMethod = $refClass->getMethod('__invoke');
            }
        } elseif (method_exists($root, $resolverName)) {
            $resolver = $root;
            $refMethod = new \ReflectionMethod(ClassUtils::getClass($root), $resolverName);
        }

        if ($resolver && $refMethod) {
            $resolveContext = new ResolverContext();
            $resolveContext->setDefinition($this->query);
            $resolveContext->setArgs($args);
            $resolveContext->setRoot($root);
            $resolveContext->setDefinitionManager($this->manager);
            $resolveContext->setResolveInfo($resolveInfo);

            if ($resolver instanceof AbstractResolver) {
                $resolver->setContext($resolveContext);
            }

            //A very strange issue are causing the fail of some tests without this clear
            //everything indicates that is a issue with cached entities through test executions
            //any clear on tests or during load fixtures does not have any effect
            //I'm not sure if this patch has any other side effect
            //Reproduce: comment the following line and run all integration tests
            //FIXME: find the cause of the issue and fix it
            $this->container->get('doctrine')->getManager()->clear();
            $params = $this->prepareMethodParameters($refMethod, $args);

            return $refMethod->invokeArgs($resolver, $params);
        }

        $error = sprintf('The resolver "%s" for query "%s" is not a valid resolver. Resolvers should have a method "__invoke(...)"', $resolverName, $this->query->getName());
        throw new \RuntimeException($error);
    }

    /**
     * @param \ReflectionMethod $refMethod
     * @param array             $args
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function prepareMethodParameters(\ReflectionMethod $refMethod, array $args): array
    {
        //normalize arguments
        $normalizedArguments = [];
        foreach ($args as $key => $value) {
            if ($this->query->hasArgument($key)) {
                $argument = $this->query->getArgument($key);
                if ('input' === $key) {
                    $normalizedValue = $value;
                } else {
                    $normalizedValue = $this->normalizeValue($value, $argument->getType());

                    //normalize argument into respective inputs objects
                    if (is_array($normalizedValue) && $this->manager->hasType($argument->getType())) {
                        if ($argument->isList()) {
                            $tmp = [];
                            foreach ($normalizedValue as $childValue) {
                                $tmp[] = $this->arrayToObject($childValue, $this->manager->getType($argument->getType()));
                            }
                            $normalizedValue = $tmp;
                        } else {
                            $normalizedValue = $this->arrayToObject($normalizedValue, $this->manager->getType($argument->getType()));
                        }
                    }
                }
                $normalizedArguments[$argument->getName()] = $normalizedValue;
                $normalizedArguments[$argument->getInternalName()] = $normalizedValue;
            }
        }

        $normalizedArguments['args'] = $normalizedArguments;
        $indexedArguments = $this->resolveMethodArguments($refMethod, $normalizedArguments);
        ksort($indexedArguments);

        return $indexedArguments;
    }

    /**
     * @param \ReflectionMethod $method
     * @param array             $incomeArgs
     *
     * @return array
     */
    protected function resolveMethodArguments(\ReflectionMethod $method, array $incomeArgs)
    {
        $orderedArguments = [];
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                $orderedArguments[$parameter->getPosition()] = $parameter->getDefaultValue();
            }
            foreach ($incomeArgs as $key => $value) {
                if ($parameter->getName() === $key) {
                    $orderedArguments[$parameter->getPosition()] = $value;
                    continue 2;
                }
            }

            //inject root common argument
            if ($this->root
                && 'root' === $parameter->getName()
                && $parameter->getClass()
                && $parameter->getClass()->isInstance($this->root)

            ) {
                $orderedArguments[$parameter->getPosition()] = $this->root;
            }
        }

        return $orderedArguments;
    }

    /**
     * @param mixed  $value
     * @param string $type
     *
     * @return mixed
     */
    protected function normalizeValue($value, string $type)
    {
        if (Type::ID === $type) {
            if (\is_array($value)) {
                $idsArray = [];
                foreach ($value as $id) {
                    $idsArray[] = ID::createFromString($id);
                }
                $value = $idsArray;
            } else {
                $value = ID::createFromString($value);
            }
        }

        return $value;
    }

    /**
     * Convert a array into object using given definition
     *
     * @param array                     $data       data to populate the object
     * @param ObjectDefinitionInterface $definition object definition
     *
     * @return mixed
     */
    protected function arrayToObject(array $data, ObjectDefinitionInterface $definition)
    {
        $class = $definition->getClass();

        //normalize data
        foreach ($data as $fieldName => &$value) {
            if (!$definition->hasField($fieldName)) {
                continue;
            }
            $fieldDefinition = $definition->getField($fieldName);
            $value = $this->normalizeValue($value, $fieldDefinition->getType());
        }
        unset($value);

        //instantiate object
        if (class_exists($class)) {
            $object = new $class();
        } else {
            return $data;
        }

        //populate object
        foreach ($data as $key => $value) {
            if (!$definition->hasField($key)) {
                continue;
            }
            $fieldDefinition = $definition->getField($key);
            $this->setObjectValue($object, $fieldDefinition, $value);
        }

        return $object;
    }

    /**
     * @param mixed           $object
     * @param FieldDefinition $fieldDefinition
     * @param mixed           $value
     */
    protected function setObjectValue($object, FieldDefinition $fieldDefinition, $value)
    {
        //using setter
        $accessor = new PropertyAccessor();
        $propertyName = $fieldDefinition->getOriginName();
        if ($propertyName) {
            if ($accessor->isWritable($object, $propertyName)) {
                $accessor->setValue($object, $propertyName, $value);
            } else {
                //using reflection
                $refClass = new \ReflectionClass(\get_class($object));
                if ($refClass->hasProperty($fieldDefinition->getOriginName()) && $property = $refClass->getProperty($fieldDefinition->getOriginName())) {
                    $property->setAccessible(true);
                    $property->setValue($object, $value);
                }
            }
        }
    }
}
