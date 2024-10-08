<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Doctrine\Provider;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use EonX\EasyBatch\Doctrine\Type\DateTimeWithMicroSecondsType;

final class DoctrineDbalStatementProvider
{
    private const DEFAULT_BATCH_ITEM_TABLE = 'easy_batch_items';

    private const DEFAULT_BATCH_TABLE = 'easy_batches';

    private ?Closure $extendBatchItemsTable = null;

    private ?Closure $extendBatchesTable = null;

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $batchesTable = self::DEFAULT_BATCH_TABLE,
        private readonly string $batchItemsTable = self::DEFAULT_BATCH_ITEM_TABLE,
    ) {
        // Register types
        if (Type::hasType(DateTimeWithMicroSecondsType::NAME) === false) {
            Type::addType(DateTimeWithMicroSecondsType::NAME, DateTimeWithMicroSecondsType::class);
        }
    }

    public function extendBatchItemsTable(callable $callable): self
    {
        $this->extendBatchItemsTable = $callable(...);

        return $this;
    }

    public function extendBatchesTable(callable $callable): self
    {
        $this->extendBatchesTable = $callable(...);

        return $this;
    }

    /**
     * @return string[]
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function migrateStatements(): array
    {
        $schema = new Schema();

        $batchesTable = $schema->createTable($this->batchesTable);
        $batchesTable->addColumn('cancelled', 'integer');
        $batchesTable->addColumn('failed', 'integer');
        $batchesTable->addColumn('succeeded', 'integer');
        $batchesTable->addColumn('processed', 'integer');
        $batchesTable->addColumn('total', 'integer');
        $batchesTable->addColumn('parent_batch_item_id', 'guid', [
            'notNull' => false,
        ]);

        $batchItemsTable = $schema->createTable($this->batchItemsTable);
        $batchItemsTable->addColumn('batch_id', 'guid');
        $batchItemsTable->addColumn('attempts', 'integer');
        $batchItemsTable->addColumn('max_attempts', 'integer');
        $batchItemsTable->addColumn('encrypted', 'integer');
        $batchItemsTable->addColumn('message', 'text', [
            'notNull' => false,
        ]);
        $batchItemsTable->addColumn('depends_on_name', 'string', [
            'length' => 191,
            'notNull' => false,
        ]);

        $this->addSharedColumns($batchesTable);
        $this->addSharedColumns($batchItemsTable);

        // Index for pagination over items
        $batchItemsTable->addIndex(['batch_id', 'depends_on_name', 'status', 'created_at'], 'srch_depends_on');

        if ($this->extendBatchesTable !== null) {
            \call_user_func($this->extendBatchesTable, $batchesTable);
        }

        if ($this->extendBatchItemsTable !== null) {
            \call_user_func($this->extendBatchItemsTable, $batchItemsTable);
        }

        return $schema->toSql($this->connection->getDatabasePlatform());
    }

    /**
     * @return string[]
     */
    public function rollbackStatements(): array
    {
        return [
            \sprintf('DROP TABLE %s;', $this->batchItemsTable),
            \sprintf('DROP TABLE %s;', $this->batchesTable),
        ];
    }

    private function addSharedColumns(Table $table): void
    {
        $table->addColumn('id', 'guid');
        $table->setPrimaryKey(['id']);

        $table->addColumn('class', 'string', [
            'length' => 191,
        ]);

        $table->addColumn('metadata', 'text', [
            'notNull' => false,
        ]);

        $table->addColumn('name', 'string', [
            'length' => 191,
            'notNull' => false,
        ]);

        $table->addColumn('status', 'string', [
            'length' => 50,
        ]);

        $table->addColumn('requires_approval', 'integer');

        $table->addColumn('throwable', 'text', [
            'notNull' => false,
        ]);

        $table->addColumn('type', 'string', [
            'length' => 191,
            'notNull' => false,
        ]);

        $table->addColumn('cancelled_at', DateTimeWithMicroSecondsType::NAME, [
            'notNull' => false,
        ]);

        $table->addColumn('started_at', DateTimeWithMicroSecondsType::NAME, [
            'notNull' => false,
        ]);

        $table->addColumn('finished_at', DateTimeWithMicroSecondsType::NAME, [
            'notNull' => false,
        ]);

        $table->addColumn('created_at', DateTimeWithMicroSecondsType::NAME);
        $table->addColumn('updated_at', DateTimeWithMicroSecondsType::NAME);
    }
}
