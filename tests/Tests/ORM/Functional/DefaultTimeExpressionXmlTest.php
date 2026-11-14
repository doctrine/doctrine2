<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\OrmFunctionalTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class DefaultTimeExpressionXmlTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    #[IgnoreDeprecations]
    public function testUsingTimeRelatedDefaultExpressionCausesAnOrmDeprecationAndNoDbalDeprecation(): void
    {
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if (
            $platform->getCurrentTimestampSQL() !== 'CURRENT_TIMESTAMP'
            || $platform->getCurrentTimeSQL() !== 'CURRENT_TIME'
            || $platform->getCurrentDateSQL() !== 'CURRENT_DATE'
        ) {
            $this->markTestSkipped('Platform does not use standard SQL for current time expressions.');
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped(
                'MySQL platform does not support CURRENT_TIME or CURRENT_DATE as default expression.',
            );
        }

        $this->_em         = $this->getEntityManager(
            mappingDriver: new XmlDriver(__DIR__ . '/../Mapping/xml/'),
        );
        $this->_schemaTool = new SchemaTool($this->_em);

        $this->expectException(LogicException::class);

        $this->createSchemaForModels(XmlLegacyTimeEntity::class);
    }

    public function testUsingDefaultExpressionInstancesCausesNoDeprecationXmlDriver(): void
    {
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped(
                'MySQL platform does not support CURRENT_TIME or CURRENT_DATE as default expression.',
            );
        }

        $this->_em         = $this->getEntityManager(
            mappingDriver: new XmlDriver(__DIR__ . '/../Mapping/xml/'),
        );
        $this->_schemaTool = new SchemaTool($this->_em);

        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/12252');
        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/dbal/pull/7195');

        $this->createSchemaForModels(XmlTimeEntity::class);
        $this->_em->persist($entity = new XmlTimeEntity());
        $this->_em->flush();
        $this->_em->clear();
        $this->_em->find(XmlTimeEntity::class, $entity->id);
    }
}

class XmlLegacyTimeEntity
{
    public int $id;

    public DateTime $createdAt;
    public DateTimeImmutable $createdAtImmutable;
    public DateTime $createdTime;
    public DateTime $createdDate;
}

class XmlTimeEntity
{
    public int $id;

    public DateTime $createdAt;
    public DateTimeImmutable $createdAtImmutable;
    public DateTime $createdTime;
    public DateTime $createdDate;
}
