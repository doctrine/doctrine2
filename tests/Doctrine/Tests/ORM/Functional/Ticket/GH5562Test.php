<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

use function mt_rand;

final class GH5562Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(GH5562User::class),
                $this->_em->getClassMetadata(GH5562Manager::class),
                $this->_em->getClassMetadata(GH5562Merchant::class),
            ]
        );
    }

    /**
     * @group GH-5562
     */
    public function testCacheShouldBeUpdatedWhenAssociationChanges(): void
    {
        $manager  = new GH5562Manager();
        $merchant = new GH5562Merchant();

        $manager->username = 'username';
        $manager->merchant = $merchant;
        $merchant->manager = $manager;

        $merchant->name = 'Merchant';

        $this->_em->persist($merchant);
        $this->_em->persist($manager);
        $this->_em->flush();
        $this->_em->clear();

        $merchant = $this->_em->find(GH5562Merchant::class, $merchant->id);

        $merchant->name              = mt_rand();
        $merchant->manager->username = 'usernameUPDATE';

        $this->_em->flush();
        $this->_em->clear();

        $merchant = $this->_em->find(GH5562Merchant::class, $merchant->id);

        self::assertEquals('usernameUPDATE', $merchant->manager->username);
    }
}

/**
 * @Entity
 * @Cache(usage="NONSTRICT_READ_WRITE")
 */
class GH5562Merchant
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="IDENTITY")
     */
    public $id;

    /**
     * @var GH5562Manager
     * @OneToOne(targetEntity=GH5562Manager::class, mappedBy="merchant")
     * @Cache(usage="NONSTRICT_READ_WRITE")
     */
    public $manager;

    /**
     * @var string
     * @Column(name="name", type="string", length=255, nullable=false)
     */
    public $name;
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"MANAGER"  = GH5562Manager::class})
 */
abstract class GH5562User
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="IDENTITY")
     */
    public $id;
}

/**
 * @Entity
 * @Cache(usage="NONSTRICT_READ_WRITE")
 */
class GH5562Manager extends GH5562User
{
    /**
     * @var string
     * @Column
     */
    public $username;

    /**
     * @var GH5562Merchant
     * @OneToOne(targetEntity=GH5562Merchant::class, inversedBy="manager")
     */
    public $merchant;
}
