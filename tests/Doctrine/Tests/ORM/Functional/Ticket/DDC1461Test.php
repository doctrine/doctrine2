<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function get_class;

/**
 * @group DDC-1461
 */
class DDC1461Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1461TwitterAccount::class),
                    $this->_em->getClassMetadata(DDC1461User::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testChangeDetectionDeferredExplicit(): void
    {
        $user = new DDC1461User();
        $this->_em->persist($user);
        $this->_em->flush();

        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->_em->getUnitOfWork()->getEntityState($user, UnitOfWork::STATE_NEW), 'Entity should be managed.');
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->_em->getUnitOfWork()->getEntityState($user), 'Entity should be managed.');

        $acc                  = new DDC1461TwitterAccount();
        $user->twitterAccount = $acc;

        $this->_em->persist($user);
        $this->_em->flush();

        $user = $this->_em->find(get_class($user), $user->id);
        $this->assertNotNull($user->twitterAccount);
    }
}

/**
 * @Entity
 * @ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class DDC1461User
{
    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC1461TwitterAccount
     * @OneToOne(targetEntity="DDC1461TwitterAccount", orphanRemoval=true, fetch="EAGER", cascade = {"persist"}, inversedBy="user")
     */
    public $twitterAccount;
}

/**
 * @Entity
 * @ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class DDC1461TwitterAccount
{
    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC1461User
     * @OneToOne(targetEntity="DDC1461User", fetch="EAGER")
     */
    public $user;
}
