<?php

namespace Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function is_subclass_of;
use function sprintf;

final class MiddlewaresPass implements CompilerPassInterface
{
    /** @var string  */
    private $connectionDefsParam;

    /** @var string  */
    private $middlewareTag;

    public function __construct(
        string $connectionDefsParam = 'doctrine.connections',
        string $middlewareTag = 'doctrine.middleware'
    ) {
        $this->connectionDefsParam = $connectionDefsParam;
        $this->middlewareTag       = $middlewareTag;
    }

    public function process(ContainerBuilder $container): void
    {
        $middlewareAbstractDefs = [];
        foreach (array_keys($container->findTaggedServiceIds($this->middlewareTag)) as $id) {
            $middlewareAbstractDefs[$id] = $container->getDefinition($id);
        }

        foreach ($container->getParameter($this->connectionDefsParam) as $name => $id) {
            $middlewareDefs = [];
            foreach ($middlewareAbstractDefs as $id => $abstractDef) {
                $middlewareDefs[] = $childDef = $container->setDefinition(
                    sprintf('%s.%s', $id, $name),
                    new ChildDefinition($id)
                );

                if (! is_subclass_of($abstractDef->getClass(), ConnectionNameAwareInterface::class)) {
                    continue;
                }

                $childDef->addMethodCall('setConnectionName', [$name]);
            }

            $container
                ->getDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $name))
                ->addMethodCall('setMiddlewares', [$middlewareDefs]);
        }
    }
}
