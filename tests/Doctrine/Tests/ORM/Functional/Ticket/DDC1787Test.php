<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1787
 */
class DDC1787Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1787Foo::class),
                $this->_em->getClassMetadata(DDC1787Bar::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $bar  = new DDC1787Bar();
        $bar2 = new DDC1787Bar();

        $this->_em->persist($bar);
        $this->_em->persist($bar2);
        $this->_em->flush();

        $this->assertSame(1, $bar->getVersion());
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"bar" = "DDC1787Bar", "foo" = "DDC1787Foo"})
 */
class DDC1787Foo
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    private $version;

    public function getVersion(): int
    {
        return $this->version;
    }
}

/**
 * @Entity
 */
class DDC1787Bar extends DDC1787Foo
{
}
