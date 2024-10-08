<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Tests\Unit\Doctrine\Repository;

use EonX\EasyBatch\Common\Enum\BatchObjectStatus;
use EonX\EasyBatch\Common\Factory\BatchItemFactoryInterface;
use EonX\EasyBatch\Common\Repository\BatchItemRepositoryInterface;
use EonX\EasyBatch\Doctrine\Repository\BatchItemRepository;
use EonX\EasyBatch\Tests\Unit\Common\Repository\AbstractRepositoriesTestCase;
use EonX\EasyPagination\Pagination\Pagination;
use EonX\EasyPagination\Paginator\LengthAwarePaginatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class BatchItemRepositoryTest extends AbstractRepositoriesTestCase
{
    /**
     * @see testFindForDispatch
     */
    public static function provideFindForDispatchData(): iterable
    {
        yield 'Fetch only batchItems for batch and no dependency' => [
            static function (BatchItemFactoryInterface $factory, BatchItemRepositoryInterface $repo): void {
                $batchItem1 = $factory->create('batch-id', new stdClass());
                $batchItem1->setName('right-one');
                $batchItem1->setMetadata(['key' => 'value']);

                $batchItem2 = $factory->create('another-batch-id', new stdClass());
                $batchItem3 = $factory->create('batch-id', new stdClass())
                    ->setDependsOnName('dependency');

                $repo->save($batchItem1);
                $repo->save($batchItem2);
                $repo->save($batchItem3);
            },
            static function (LengthAwarePaginatorInterface $paginator): void {
                self::assertCount(1, $paginator->getItems());
                self::assertEquals('right-one', $paginator->getItems()[0]->getName());
            },
        ];

        yield 'Fetch only batchItems for batch and given dependency' => [
            static function (BatchItemFactoryInterface $factory, BatchItemRepositoryInterface $repo): void {
                $batchItem1 = $factory->create('batch-id', new stdClass());
                $batchItem1->setName('right-one');
                $batchItem1->setDependsOnName('dependency');

                $batchItem2 = $factory->create('another-batch-id', new stdClass());
                $batchItem3 = $factory->create('batch-id', new stdClass());
                $batchItem3->setName('dependency');

                $repo->save($batchItem1);
                $repo->save($batchItem2);
                $repo->save($batchItem3);
            },
            static function (LengthAwarePaginatorInterface $paginator): void {
                self::assertCount(1, $paginator->getItems());
                self::assertEquals('right-one', $paginator->getItems()[0]->getName());
            },
            'dependency',
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function testFindCountsForBatch(): void
    {
        $factory = $this->getBatchItemFactory();
        $repo = $this->getBatchItemRepository($factory);

        $batchItem1 = $factory->create('batch-id', new stdClass());
        $batchItem2 = $factory->create('batch-id', new stdClass());
        $batchItem2->setStatus(BatchObjectStatus::Succeeded);

        $repo->save($batchItem1);
        $repo->save($batchItem2);

        $counts = $repo->findCountsForBatch('batch-id');

        self::assertEquals(0, $counts->countCancelled());
        self::assertEquals(0, $counts->countFailed());
        self::assertEquals(1, $counts->countProcessed());
        self::assertEquals(1, $counts->countSucceeded());
        self::assertEquals(2, $counts->countTotal());
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[DataProvider('provideFindForDispatchData')]
    public function testFindForDispatch(callable $setup, callable $test, ?string $dependsOnName = null): void
    {
        $factory = $this->getBatchItemFactory();
        $repo = $this->getBatchItemRepository($factory);

        \call_user_func($setup, $factory, $repo);

        $paginator = $repo->paginateItems(new Pagination(1, 15), 'batch-id', $dependsOnName);

        \call_user_func($test, $paginator);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function getBatchItemRepository(BatchItemFactoryInterface $batchItemFactory): BatchItemRepositoryInterface
    {
        return new BatchItemRepository(
            $batchItemFactory,
            $this->getIdStrategy(),
            $this->getBatchItemTransformer(),
            $this->getDoctrineDbalConnection(),
            'easy_batch_items'
        );
    }
}
