<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection;
use EonX\EasyBatch\Common\Factory\BatchItemFactoryInterface;
use EonX\EasyBatch\Common\Manager\BatchObjectManagerInterface;
use EonX\EasyBatch\Common\Repository\BatchItemRepositoryInterface;
use EonX\EasyBatch\Common\Repository\BatchRepositoryInterface;
use EonX\EasyTest\EasyEventDispatcher\Dispatcher\EventDispatcherStub;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class EasyBatchBundleTestContext
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function getBatchItemFactory(): BatchItemFactoryInterface
    {
        return $this->container->get(BatchItemFactoryInterface::class);
    }

    public function getBatchItemRepository(): BatchItemRepositoryInterface
    {
        return $this->container->get(BatchItemRepositoryInterface::class);
    }

    public function getBatchObjectManager(): BatchObjectManagerInterface
    {
        return $this->container->get(BatchObjectManagerInterface::class);
    }

    public function getBatchRepository(): BatchRepositoryInterface
    {
        return $this->container->get(BatchRepositoryInterface::class);
    }

    public function getConnection(): Connection
    {
        return $this->container->get(Connection::class);
    }

    public function getEventDispatcher(): EventDispatcherStub
    {
        /** @var \EonX\EasyTest\EasyEventDispatcher\Dispatcher\EventDispatcherStub $eventDispatcher */
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        return $eventDispatcher;
    }
}
