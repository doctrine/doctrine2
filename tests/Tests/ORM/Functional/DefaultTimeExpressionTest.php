<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use LogicException;

class DefaultTimeExpressionTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    public function testUsingTimeRelatedDefaultExpressionCausesAnOrmDeprecationAndNoDbalDeprecation(): void
    {
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if (
            $platform->getCurrentTimestampSQL() !== 'CURRENT_TIMESTAMP'
            || $platform->getCurrentTimeSQL() !== 'CURRENT_TIME'
            || $platform->getCurrentDateSQL() !== 'CURRENT_DATE'
        ) {
            $this->markTestSkipped(
                'This test requires platforms to support exactly CURRENT_TIMESTAMP, CURRENT_TIME and CURRENT_DATE.',
            );
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped(
                'MySQL platform does not support CURRENT_TIME or CURRENT_DATE as default expression.',
            );
        }

        $this->expectException(LogicException::class);
        $this->createSchemaForModels(LegacyTimeEntity::class);
    }

    public function testUsingDefaultExpressionInstancesCausesNoDeprecation(): void
    {
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped('MySQL platform does not support CURRENT_TIME or CURRENT_DATE as default expression.');
        }

        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/dbal/pull/7195');

        $this->createSchemaForModels(TimeEntity::class);
        $this->_em->persist($entity = new TimeEntity());
        $this->_em->flush();
        $this->_em->find(TimeEntity::class, $entity->id);
    }
}

#[ORM\Entity]
class LegacyTimeEntity
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column(
        type: Types::DATETIME_MUTABLE,
        options: ['default' => 'CURRENT_TIMESTAMP'],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdAt;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        options: ['default' => 'CURRENT_TIMESTAMP'],
        insertable: false,
        updatable: false,
    )]
    public DateTimeImmutable $createdAtImmutable;

    #[ORM\Column(
        type: Types::TIME_MUTABLE,
        options: ['default' => 'CURRENT_TIME'],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdTime;

    #[ORM\Column(
        type: Types::DATE_MUTABLE,
        options: ['default' => 'CURRENT_DATE'],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdDate;
}

#[ORM\Entity]
class TimeEntity
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column(
        type: Types::DATETIME_MUTABLE,
        options: ['default' => new CurrentTimestamp()],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdAt;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        options: ['default' => new CurrentTimestamp()],
        insertable: false,
        updatable: false,
    )]
    public DateTimeImmutable $createdAtImmutable;

    #[ORM\Column(
        type: Types::TIME_MUTABLE,
        options: ['default' => new CurrentTime()],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdTime;

    #[ORM\Column(
        type: Types::DATE_MUTABLE,
        options: ['default' => new CurrentDate()],
        insertable: false,
        updatable: false,
    )]
    public DateTime $createdDate;
}
