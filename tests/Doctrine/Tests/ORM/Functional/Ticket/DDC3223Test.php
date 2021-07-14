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
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Functional tests for get Id after clone child entity
 */
class DDC3223Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                Journalist::class,
                Participant::class,
                Status::class,
                ProfileStatus::class,
            ]
        );
    }

    public function testIssueGetId(): void
    {
        $profileStatus = new ProfileStatus();

        $participant                = new Journalist();
        $participant->profileStatus = $profileStatus;

        $this->_em->persist($profileStatus);
        $this->_em->persist($participant);
        $this->_em->flush();
        $this->_em->clear();

        $participant = $this->_em->find(Participant::class, $participant->id);

        $profileStatus = clone $participant->profileStatus;

        $this->assertSame(1, $profileStatus->getId(), 'The identifier on the cloned instance is an integer');
    }
}

/** @Entity @Table(name="ddc3223_journalist") */
class Journalist extends Participant
{
}

/**
 * @Entity @Table(name="ddc3223_participant")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *     "journalist"  = "Journalist",
 *     "participant" = "Participant",
 * })
 */
class Participant
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var ProfileStatus
     * @ManyToOne(targetEntity="ProfileStatus")
     */
    public $profileStatus;
}

/**
 * @Entity @Table(name="ddc3223_status")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *     "profile" = "ProfileStatus",
 *     "status"  = "Status",
 * })
 */
class Status
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class ProfileStatus extends Status
{
}
