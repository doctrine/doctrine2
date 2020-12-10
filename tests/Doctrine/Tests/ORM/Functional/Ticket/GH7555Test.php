<?php

declare(strict_types=1);

namespace Doctrine\Tests\Functional\Ticket;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Tests\OrmFunctionalTestCase;
use PDOException;

/**
 * @see https://github.com/doctrine/orm/issues/7555
 *
 * @group GH7555
 */
final class GH7555Test extends OrmFunctionalTestCase
{
    private static $tableCreated = false;

    protected function setUp() : void
    {
        parent::setUp();

        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'postgresql') {
            $this->markTestSkipped('Only databases supporting deferrable constraints are eligible for this test.');
        }

        if (self::$tableCreated) {
            return;
        }

        $this->setUpEntitySchema([GH7555Entity::class]);
        $connection = $this->_em->getConnection();
        $connection->exec('DROP INDEX "unique_field_constraint"');
        $connection->exec('ALTER TABLE "gh7555entity" ADD CONSTRAINT "unique_field_constraint" UNIQUE ("uniquefield") DEFERRABLE');

        $this->_em->persist(new GH7555Entity());
        $this->_em->flush();
        $this->_em->clear();

        self::$tableCreated = true;
    }

    /**
     * @group GH7555
     */
    public function testTransactionalWithDeferredConstraint() : void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "unique_field_constraint"');

        $this->_em->transactional(static function (EntityManagerInterface $entityManager) : void {
            $entityManager->getConnection()->exec('SET CONSTRAINTS "unique_field_constraint" DEFERRED');
            $entityManager->persist(new GH7555Entity());
        });
    }

    /**
     * @group GH7555
     */
    public function testTransactionalWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "unique_field_constraint"');

        $this->_em->getConnection()->setNestTransactionsWithSavepoints(true);

        $this->_em->transactional(static function (EntityManagerInterface $entityManager) : void {
            $entityManager->getConnection()->exec('SET CONSTRAINTS "unique_field_constraint" DEFERRED');
            $entityManager->persist(new GH7555Entity());
            $entityManager->flush();
        });
    }

    /**
     * @group GH7555
     */
    public function testFlushWithDeferredConstraint() : void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "unique_field_constraint"');

        $this->_em->beginTransaction();
        $this->_em->getConnection()->exec('SET CONSTRAINTS "unique_field_constraint" DEFERRED');
        $this->_em->persist(new GH7555Entity());
        $this->_em->flush();
        $this->_em->commit();
    }

    /**
     * @group GH7555
     */
    public function testFlushWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "unique_field_constraint"');

        $this->_em->getConnection()->setNestTransactionsWithSavepoints(true);

        $this->_em->beginTransaction();
        $this->_em->getConnection()->exec('SET CONSTRAINTS "unique_field_constraint" DEFERRED');
        $this->_em->persist(new GH7555Entity());
        $this->_em->flush();
        $this->_em->commit();
    }
}

/**
 * @Entity
 * @Table(
 *     uniqueConstraints={
 *          @UniqueConstraint(columns={"uniqueField"}, name="unique_field_constraint")
 *     }
 * )
 */
class GH7555Entity
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     *
     * @var int
     */
    public $id;

    /**
     * @Column(type="boolean")
     *
     * @var bool
     */
    public $uniqueField = true;
}
