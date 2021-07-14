<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2780
 */
class DDC2780Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2780User::class),
                $this->_em->getClassMetadata(DDC2780Project::class),
            ]
        );
    }

    /**
     * Verifies that IS [NOT] NULL can be used on join aliases
     */
    public function testIssue(): void
    {
        $user    = new DDC2780User();
        $project = new DDC2780Project();

        $user->project = $project;

        $this->_em->persist($project);
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->select('user')
            ->from(DDC2780User::class, 'user')
            ->leftJoin('user.project', 'project')
            ->where('project IS NOT NULL')
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertInstanceOf(DDC2780User::class, $result);
    }
}

/**
 * @Entity
 */
class DDC2780User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @ManyToOne(targetEntity="DDC2780Project")
     * @var DDC2780Project
     */
    public $project;
}

/** @Entity */
class DDC2780Project
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @OneToMany(targetEntity="DDC2780User", mappedBy="project")
     * @var DDC2780User[]
     */
    public $users;

    /** Constructor */
    public function __construct()
    {
        $this->users = new ArrayCollection();
    }
}
