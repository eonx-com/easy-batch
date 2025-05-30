<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Doctrine\Repository;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use EonX\EasyBatch\Common\Enum\BatchObjectStatus;
use EonX\EasyBatch\Common\Exception\BatchItemNotFoundException;
use EonX\EasyBatch\Common\Repository\BatchItemRepositoryInterface;
use EonX\EasyBatch\Common\ValueObject\BatchCounts;
use EonX\EasyBatch\Common\ValueObject\BatchItem;
use EonX\EasyPagination\Pagination\PaginationInterface;
use EonX\EasyPagination\Paginator\DoctrineDbalLengthAwarePaginator;
use EonX\EasyPagination\Paginator\LengthAwarePaginatorInterface;

final class BatchItemRepository extends AbstractBatchObjectRepository implements BatchItemRepositoryInterface
{
    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function findCountsForBatch(int|string $batchId): BatchCounts
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('status', 'count(id) as _count')
            ->from($this->table)
            ->where('batch_id = :batchId')
            ->setParameter('batchId', $batchId, \is_string($batchId) ? Types::STRING : Types::INTEGER)
            ->groupBy('status');

        $results = $this->connection->fetchAllAssociative(
            $queryBuilder->getSQL(),
            $queryBuilder->getParameters(),
            $queryBuilder->getParameterTypes()
        );

        $countProcessed = 0;
        $countTotal = 0;
        $results = \array_column($results, '_count', 'status');

        foreach ($results as $status => $count) {
            if (
                \in_array(
                    $status,
                    BatchObjectStatus::extractValues(BatchObjectStatus::STATUSES_FOR_COMPLETE),
                    true
                )
            ) {
                $countProcessed += $count;
            }

            $countTotal += $count;
        }

        /** @var int $countCancelled */
        $countCancelled = $results[BatchObjectStatus::Cancelled->value] ?? 0;
        /** @var int $countFailed */
        $countFailed = $results[BatchObjectStatus::Failed->value] ?? 0;
        /** @var int $countSucceeded */
        $countSucceeded = $results[BatchObjectStatus::Succeeded->value] ?? 0;

        return new BatchCounts(
            $countCancelled,
            $countFailed,
            $countProcessed,
            $countSucceeded,
            $countTotal
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \EonX\EasyBatch\Common\Exception\BatchItemNotFoundException
     * @throws \EonX\EasyBatch\Common\Exception\BatchObjectIdRequiredException
     */
    public function findForProcess(int|string $batchItemId): BatchItem
    {
        $batchItem = $this->findOrFail($batchItemId);

        if ($batchItem->getStatus() === BatchObjectStatus::Created) {
            $this->updateStatusToPending([$batchItem]);
        }

        return $batchItem;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \EonX\EasyBatch\Common\Exception\BatchItemNotFoundException
     */
    public function findOrFail(int|string $batchItemId): BatchItem
    {
        /** @var \EonX\EasyBatch\Common\ValueObject\BatchItem|null $batchItem */
        $batchItem = $this->doFind($batchItemId);

        if ($batchItem !== null) {
            return $batchItem;
        }

        throw new BatchItemNotFoundException(\sprintf('BatchItem for id "%s" not found', $batchItemId));
    }

    public function paginateItems(
        PaginationInterface $pagination,
        int|string $batchId,
        ?string $dependsOnName = null,
    ): LengthAwarePaginatorInterface {
        $paginator = new DoctrineDbalLengthAwarePaginator($pagination, $this->connection, $this->table);

        $paginator->setFilterCriteria(
            static function (QueryBuilder $queryBuilder) use ($batchId, $dependsOnName): void {
                $queryBuilder
                    ->where('batch_id = :batchId')
                    ->setParameter('batchId', $batchId, \is_string($batchId) ? Types::STRING : Types::INTEGER);

                // Make sure to get only batchItems with no dependency
                if ($dependsOnName === null) {
                    $queryBuilder->andWhere('depends_on_name is null');
                }

                // Make sure to get only batchItems for given dependency
                if ($dependsOnName !== null) {
                    $queryBuilder
                        ->andWhere('depends_on_name = :dependsOnName')
                        ->setParameter('dependsOnName', $dependsOnName, Types::STRING);
                }
            }
        );

        $paginator->setGetItemsCriteria(static function (QueryBuilder $queryBuilder): void {
            $queryBuilder->orderBy('created_at');
        });

        $paginator->setTransformer(function (array $item): BatchItem {
            /** @var \EonX\EasyBatch\Common\ValueObject\BatchItem $batchItem */
            $batchItem = $this->factory->createFromArray($item);

            return $batchItem;
        });

        return $paginator;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function save(BatchItem $batchItem): BatchItem
    {
        $this->doSave($batchItem);

        return $batchItem;
    }

    /**
     * @param \EonX\EasyBatch\Common\ValueObject\BatchItem[] $batchItems
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \EonX\EasyBatch\Common\Exception\BatchObjectIdRequiredException
     */
    public function updateStatusToPending(array $batchItems): void
    {
        $count = \count($batchItems);

        if ($count < 1) {
            return;
        }

        $batchItemIds = \array_map(
            static fn (BatchItem $batchItem): int|string => $batchItem->getIdOrFail(),
            $batchItems
        );

        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->update($this->table)
            ->set('status', ':statusPending')
            ->where('status = :statusCreated')
            ->setParameter('statusPending', BatchObjectStatus::Pending->value, Types::STRING)
            ->setParameter('statusCreated', BatchObjectStatus::Created->value, Types::STRING);

        // Handle 1 batchItem
        if ($count === 1) {
            $queryBuilder
                ->andWhere('id = :batchItemId')
                ->setParameter('batchItemId', $batchItemIds[0], Types::STRING);
        }

        // Handle more than 1 batchItem
        if ($count > 1) {
            $batchItemIds = \array_map(
                function (string $batchItemId): string {
                    /** @var string $result */
                    $result = $this->connection->quote($batchItemId);

                    return $result;
                },
                $batchItemIds
            );

            $queryBuilder->andWhere($queryBuilder->expr()->in('id', $batchItemIds));
        }

        $this->connection->executeStatement(
            $queryBuilder->getSQL(),
            $queryBuilder->getParameters(),
            $queryBuilder->getParameterTypes()
        );

        foreach ($batchItems as $batchItem) {
            $batchItem->setStatus(BatchObjectStatus::Pending);
        }
    }
}
