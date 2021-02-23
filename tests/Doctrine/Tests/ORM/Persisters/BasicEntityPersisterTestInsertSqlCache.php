<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Persisters;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\LocalColumnMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Tests\Models\GeneratorChanges\GeneratorChanges;
use Doctrine\Tests\OrmTestCase;
use function implode;
use function sprintf;

class BasicEntityPersisterTestInsertSqlCache extends OrmTestCase
{
    public function testGetInsetSqlCache() : void
    {
        $em        = $this->getTestEntityManager();
        $metadata  = $em->getClassMetadata(GeneratorChanges::class);
        $persister = new BasicEntityPersister($em, $metadata);
        $platform  = $em->getConnection()->getDatabasePlatform();

        /** @var LocalColumnMetadata $propertyId */
        $propertyId   = $metadata->getProperty('id');
        $propertyName = $metadata->getProperty('name');

        $expectedIdentity    = $this->getInsertSql($metadata, $platform, [$propertyName]);
        $expectedNotIdentity = $this->getInsertSql($metadata, $platform, [$propertyId, $propertyName]);

        $valueGenerator = $propertyId->getValueGenerator();
        static::assertEquals($expectedIdentity, $persister->getInsertSQL());
        $propertyId->setValueGenerator(null);
        static::assertEquals($expectedNotIdentity, $persister->getInsertSQL());
        $propertyId->setValueGenerator($valueGenerator);
        static::assertEquals($expectedIdentity, $persister->getInsertSQL());
    }

    /**
     * @param FieldMetadata[] $columns
     */
    public function getInsertSql(ClassMetadata $metadata, AbstractPlatform $platform, array $columnsMetadata) : string
    {
        $tableName = $metadata->table->getQuotedQualifiedName($platform);
        $columns   = [];
        $values    = [];

        /** @var ColumnMetadata $columnMetadata */
        foreach ($columnsMetadata as $columnMetadata) {
            $columns[] = $platform->quoteIdentifier($columnMetadata->getColumnName());
            $values[]  = $columnMetadata->getType()->convertToDatabaseValueSQL('?', $platform);
        }

        $columns = implode(', ', $columns);
        $values  = implode(', ', $values);

        return sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $values);
    }
}
