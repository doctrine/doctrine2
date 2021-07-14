<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1238
 */
class DDC1238Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1238User::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testIssue(): void
    {
        $user = new DDC1238User();
        $user->setName('test');

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $userId = $user->getId();
        $this->_em->clear();

        $user = $this->_em->getReference(DDC1238User::class, $userId);
        $this->_em->clear();

        $userId2 = $user->getId();
        $this->assertEquals($userId, $userId2, 'This proxy can still be initialized.');
    }

    public function testIssueProxyClear(): void
    {
        $user = new DDC1238User();
        $user->setName('test');

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        // force proxy load, getId() doesn't work anymore
        $user->getName();
        $userId = $user->getId();
        $this->_em->clear();

        $user = $this->_em->getReference(DDC1238User::class, $userId);
        $this->_em->clear();

        $user2 = $this->_em->getReference(DDC1238User::class, $userId);

        // force proxy load, getId() doesn't work anymore
        $user->getName();
        $this->assertNull($user->getId(), 'Now this is null, we already have a user instance of that type');
    }
}

/**
 * @Entity
 */
class DDC1238User
{
    /**
     * @var int|null
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    private $id;

    /**
     * @Column
     * @var string|null
     */
    private $name;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
