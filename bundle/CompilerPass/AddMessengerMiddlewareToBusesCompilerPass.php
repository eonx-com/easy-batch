<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Bundle\CompilerPass;

use EonX\EasyBatch\Messenger\Middleware\DispatchBatchMiddleware;
use EonX\EasyBatch\Messenger\Middleware\ProcessBatchItemMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class AddMessengerMiddlewareToBusesCompilerPass implements CompilerPassInterface
{
    private const EASY_BATCH_MIDDLEWARE_LIST = [
        DispatchBatchMiddleware::class,
        ProcessBatchItemMiddleware::class,
    ];

    private const MESSENGER_BUS_TAG = 'messenger.bus';

    public function process(ContainerBuilder $container): void
    {
        foreach (\array_keys($container->findTaggedServiceIds(self::MESSENGER_BUS_TAG)) as $busId) {
            $busDef = $container->getDefinition($busId);
            $middleware = $busDef->getArgument(0);

            if (($middleware instanceof IteratorArgument) === false) {
                continue;
            }

            // Remove easy batch middleware if added in the app config
            /** @var \Symfony\Component\DependencyInjection\Reference[] $existingMiddlewareList */
            $existingMiddlewareList = \array_filter(
                $middleware->getValues(),
                static fn (
                    Reference $ref,
                ): bool => \in_array((string)$ref, self::EASY_BATCH_MIDDLEWARE_LIST, true) === false
            );

            // Convert easy batch middleware classes to reference
            $easyBatchMiddlewareList = \array_map(
                static fn (string $class): Reference => new Reference($class),
                self::EASY_BATCH_MIDDLEWARE_LIST
            );

            // Add reference to easy batch middleware at the start of existing list
            \array_unshift($existingMiddlewareList, ...$easyBatchMiddlewareList);

            // Replace middleware list in bus argument
            $middleware->setValues($existingMiddlewareList);
        }
    }
}
