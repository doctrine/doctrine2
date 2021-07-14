<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function get_class;

/**
 * Functional Query tests.
 *
 * @group DDC-692
 */
class ReadOnlyTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(ReadOnlyEntity::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testReadOnlyEntityNeverChangeTracked(): void
    {
        $readOnly = new ReadOnlyEntity('Test1', 1234);
        $this->_em->persist($readOnly);
        $this->_em->flush();

        $readOnly->name         = 'Test2';
        $readOnly->numericValue = 4321;

        $this->_em->flush();
        $this->_em->clear();

        $dbReadOnly = $this->_em->find(ReadOnlyEntity::class, $readOnly->id);
        $this->assertEquals('Test1', $dbReadOnly->name);
        $this->assertEquals(1234, $dbReadOnly->numericValue);
    }

    /**
     * @group DDC-1659
     */
    public function testClearReadOnly(): void
    {
        $readOnly = new ReadOnlyEntity('Test1', 1234);
        $this->_em->persist($readOnly);
        $this->_em->flush();
        $this->_em->getUnitOfWork()->markReadOnly($readOnly);

        $this->_em->clear();

        $this->assertFalse($this->_em->getUnitOfWork()->isReadOnly($readOnly));
    }

    /**
     * @group DDC-1659
     */
    public function testClearEntitiesReadOnly(): void
    {
        $readOnly = new ReadOnlyEntity('Test1', 1234);
        $this->_em->persist($readOnly);
        $this->_em->flush();
        $this->_em->getUnitOfWork()->markReadOnly($readOnly);

        $this->_em->clear(get_class($readOnly));

        $this->assertFalse($this->_em->getUnitOfWork()->isReadOnly($readOnly));
    }

    public function testReadOnlyQueryHint(): void
    {
        $user = new ReadOnlyEntity('beberlei', 1234);

        $this->_em->persist($user);

        $this->_em->flush();
        $this->_em->clear();

        $dql = 'SELECT u FROM ' . ReadOnlyEntity::class . ' u WHERE u.id = ?1';

        $query = $this->_em->createQuery($dql);
        $query->setParameter(1, $user->id);
        $query->setHint(Query::HINT_READ_ONLY, true);

        $user = $query->getSingleResult();

        $this->assertTrue($this->_em->getUnitOfWork()->isReadOnly($user));
    }

    public function testNotReadOnlyIfObjectWasProxyBefore(): void
    {
        $user = new ReadOnlyEntity('beberlei', 1234);

        $this->_em->persist($user);

        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->getReference(ReadOnlyEntity::class, $user->id);

        $dql = 'SELECT u FROM ' . ReadOnlyEntity::class . ' u WHERE u.id = ?1';

        $query = $this->_em->createQuery($dql);
        $query->setParameter(1, $user->id);
        $query->setHint(Query::HINT_READ_ONLY, true);

        $user = $query->getSingleResult();

        $this->assertFalse($this->_em->getUnitOfWork()->isReadOnly($user));
    }

    public function testNotReadOnlyIfObjectWasKnownBefore(): void
    {
        $user = new ReadOnlyEntity('beberlei', 1234);

        $this->_em->persist($user);

        $this->_em->flush();
        $this->_em->clear();

        $userIntoIdentityMap = $this->_em->find(ReadOnlyEntity::class, $user->id);

        $dql = 'SELECT u FROM ' . ReadOnlyEntity::class . ' u WHERE u.id = ?1';

        $query = $this->_em->createQuery($dql);
        $query->setParameter(1, $user->id);
        $query->setHint(Query::HINT_READ_ONLY, true);

        $user = $query->getSingleResult();

        $this->assertFalse($this->_em->getUnitOfWork()->isReadOnly($user));
    }
}

/**
 * @Entity(readOnly=true)
 */
class ReadOnlyEntity
{
    /**
     * @Id @GeneratedValue @Column(type="integer")
     * @var int
     */
    public $id;
    /**
     * @var string
     * @Column(type="string")
     */
    public $name;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $numericValue;

    public function __construct($name, $number)
    {
        $this->name         = $name;
        $this->numericValue = $number;
    }
}
