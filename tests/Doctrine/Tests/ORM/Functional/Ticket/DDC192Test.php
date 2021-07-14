<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * @group DDC-192
 */
class DDC192Test extends OrmFunctionalTestCase
{
    public function testSchemaCreation(): void
    {
        $classes = [
            $this->_em->getClassMetadata(DDC192User::class),
            $this->_em->getClassMetadata(DDC192Phonenumber::class),
        ];

        $this->_schemaTool->createSchema($classes);

        $tables = $this->_em->getConnection()
                            ->getSchemaManager()
                            ->listTableNames();

        foreach ($classes as $class) {
            assert($class instanceof ClassMetadata);
            self::assertContains($class->getTableName(), $tables);
        }
    }
}

/**
 * @Entity
 * @Table(name="ddc192_users")
 */
class DDC192User
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(name="name", type="string")
     */
    public $name;
}


/**
 * @Entity
 * @Table(name="ddc192_phonenumbers")
 */
class DDC192Phonenumber
{
    /**
     * @var string
     * @Id
     * @Column(name="phone", type="string", length=40)
     */
    protected $phone;

    /**
     * @var DDC192User
     * @Id
     * @ManyToOne(targetEntity="DDC192User")
     * @JoinColumn(name="userId", referencedColumnName="id")
     */
    protected $user;

    public function setPhone(string $value): void
    {
        $this->phone = $value;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setUser(DDC192User $user): void
    {
        $this->user = $user;
    }

    public function getUser(): DDC192User
    {
        return $this->user;
    }
}
