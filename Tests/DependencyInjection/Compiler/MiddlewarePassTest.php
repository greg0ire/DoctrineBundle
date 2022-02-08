<?php

namespace Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

use function interface_exists;
use function sprintf;

class MiddlewarePassTest extends TestCase
{
    /** @return array<string, mixed[]> */
    public function provideAddMiddleware(): array
    {
        return [
            'not connection name aware' => [Middleware1::class, false],
            'connection name aware' => [Middleware2::class, true],
        ];
    }

    /** @dataProvider provideAddMiddleware */
    public function testAddMiddleware(string $middlewareClass, bool $connectionNameAware): void
    {
        /** @psalm-suppress UndefinedClass */
        if (! interface_exists(Middleware::class)) {
            $this->markTestSkipped(sprintf('%s needed to run this test', Middleware::class));
        }

        $container = $this->createContainer(static function (ContainerBuilder $container) use ($middlewareClass) {
            $container
                ->register('middleware', $middlewareClass)
                ->setAbstract(true)
                ->addTag('doctrine.middleware');

            $container
                ->setAlias('conf_conn1', 'doctrine.dbal.conn1_connection.configuration')
                ->setPublic(true); // Avoid removal and inlining

            $container
                ->setAlias('conf_conn2', 'doctrine.dbal.conn2_connection.configuration')
                ->setPublic(true); // Avoid removal and inlining
        });

        $this->assertMiddlewareInjected('conn1', $middlewareClass, $connectionNameAware, $container);
        $this->assertMiddlewareInjected('conn2', $middlewareClass, $connectionNameAware, $container);
    }

    public function testAddMiddlewareWithAutoconfigure(): void
    {
        /** @psalm-suppress UndefinedClass */
        if (! interface_exists(Middleware::class)) {
            $this->markTestSkipped(sprintf('%s needed to run this test', Middleware::class));
        }

        $container = $this->createContainer(static function (ContainerBuilder $container) {
            /** @psalm-suppress UndefinedClass */
            $container
                ->register('middleware', Middleware3::class)
                ->setAutoconfigured(true);

            $container
                ->setAlias('conf_conn1', 'doctrine.dbal.conn1_connection.configuration')
                ->setPublic(true); // Avoid removal and inlining

            $container
                ->setAlias('conf_conn2', 'doctrine.dbal.conn2_connection.configuration')
                ->setPublic(true); // Avoid removal and inlining
        });

        /** @psalm-suppress UndefinedClass */
        $this->assertMiddlewareInjected('conn1', Middleware3::class, false, $container);
        /** @psalm-suppress UndefinedClass */
        $this->assertMiddlewareInjected('conn2', Middleware3::class, false, $container);
    }

    private function createContainer(callable $func): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.debug' => false]));

        $container->registerExtension(new DoctrineExtension());
        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'connections' => [
                    'conn1' => ['url' => 'mysql://user:pass@server1.tld:3306/db1'],
                    'conn2' => ['url' => 'mysql://user:pass@server2.tld:3306/db2'],
                ],
            ],
        ]);

        $container->addCompilerPass(new MiddlewaresPass());

        $func($container);

        $container->compile();

        return $container;
    }

    private function assertMiddlewareInjected(
        string $connName,
        string $middlewareClass,
        bool $connectionNameAware,
        ContainerBuilder $container
    ): void {
        $calls           = $container->getDefinition('conf_' . $connName)->getMethodCalls();
        $middlewareFound = [];
        foreach ($calls as $call) {
            if ($call[0] !== 'setMiddlewares' || ! isset($call[1][0])) {
                continue;
            }

            foreach ($call[1][0] as $middlewareDefs) {
                if ($middlewareDefs->getClass() !== $middlewareClass) {
                    continue;
                }

                $middlewareFound[] = $middlewareDefs;
            }
        }

        $this->assertCount(1, $middlewareFound, sprintf(
            'Middleware not injected in doctrine.dbal.%s_connection.configuration',
            $connName
        ));

        $callsFound = [];
        foreach ($middlewareFound[0]->getMethodCalls() as $call) {
            if ($call[0] !== 'setConnectionName') {
                continue;
            }

            $callsFound[] = $call;
        }

        $this->assertCount($connectionNameAware ? 1 : 0, $callsFound);
        if (! $connectionNameAware) {
            return;
        }

        $this->assertSame($call[1][0] ?? null, $connName);
    }
}

class Middleware1
{
}

class Middleware2 implements ConnectionNameAwareInterface
{
    public function setConnectionName(string $name): void
    {
    }
}

/** @psalm-suppress UndefinedClass */
if (interface_exists(Middleware::class)) {
    class Middleware3 implements Middleware
    {
        public function wrap(Driver $driver): Driver
        {
            return $driver;
        }
    }
}
