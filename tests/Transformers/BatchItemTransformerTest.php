<?php
declare(strict_types=1);

namespace EonX\EasyBatch\Tests\Transformers;

use EonX\EasyBatch\Serializers\MessageSerializer;
use EonX\EasyBatch\Tests\AbstractTestCase;
use EonX\EasyBatch\Transformers\BatchItemTransformer;
use EonX\EasyEncryption\Encryptor;
use EonX\EasyEncryption\Factories\DefaultEncryptionKeyFactory;
use EonX\EasyEncryption\Interfaces\EncryptorInterface;
use EonX\EasyEncryption\Providers\DefaultEncryptionKeyProvider;
use EonX\EasyEncryption\Resolvers\SimpleEncryptionKeyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class BatchItemTransformerTest extends AbstractTestCase
{
    /**
     * @see testEncryptedBatchItem
     */
    public static function providerTestEncryptedBatchItem(): iterable
    {
        yield 'Encrypted' => [true];
        yield 'Not Encrypted' => [false];
    }

    #[DataProvider('providerTestEncryptedBatchItem')]
    public function testEncryptedBatchItem(bool $encrypted): void
    {
        $message = new stdClass();
        $message->key = 'value';

        $batchItem = $this->getBatchItemFactory()
            ->create('batchId', $message);
        $batchItem->setId('my-id');
        $batchItem->setEncrypted($encrypted);

        $transformer = new BatchItemTransformer(new MessageSerializer());
        if ($encrypted) {
            $transformer->setEncryptor($this->getEncryptor());
        }

        $array = $transformer->transformToArray($batchItem);
        /** @var \EonX\EasyBatch\Interfaces\BatchItemInterface $newBatchItem */
        $newBatchItem = $transformer->transformToObject($array);
        $expectedEncryptionKeyName = $encrypted ? EncryptorInterface::DEFAULT_KEY_NAME : null;

        self::assertEquals($encrypted, $array['encrypted']);
        self::assertEquals($encrypted, $newBatchItem->isEncrypted());
        self::assertEquals($expectedEncryptionKeyName, $newBatchItem->getEncryptionKeyName());
        self::assertInstanceOf(stdClass::class, $newBatchItem->getMessage());
    }

    private function getEncryptor(): EncryptorInterface
    {
        $keyResolver = new SimpleEncryptionKeyResolver(
            EncryptorInterface::DEFAULT_KEY_NAME,
            'TwzQsKkBcVlYYRQDvwgiGZemFVbpNiCr'
        );
        $keyFactory = new DefaultEncryptionKeyFactory();
        $keyProvider = new DefaultEncryptionKeyProvider($keyFactory, [$keyResolver]);

        return new Encryptor($keyFactory, $keyProvider);
    }
}
