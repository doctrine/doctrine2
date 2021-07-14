<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1400
 */
class DDC1400Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1400Article::class),
                    $this->_em->getClassMetadata(DDC1400User::class),
                    $this->_em->getClassMetadata(DDC1400UserState::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testFailingCase(): void
    {
        $article = new DDC1400Article();
        $user1   = new DDC1400User();
        $user2   = new DDC1400User();

        $this->_em->persist($article);
        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->flush();

        $userState1            = new DDC1400UserState();
        $userState1->article   = $article;
        $userState1->articleId = $article->id;
        $userState1->user      = $user1;
        $userState1->userId    = $user1->id;

        $userState2            = new DDC1400UserState();
        $userState2->article   = $article;
        $userState2->articleId = $article->id;
        $userState2->user      = $user2;
        $userState2->userId    = $user2->id;

        $this->_em->persist($userState1);
        $this->_em->persist($userState2);
        $this->_em->flush();
        $this->_em->clear();

        $user1 = $this->_em->getReference(DDC1400User::class, $user1->id);

        $this->_em->createQuery('SELECT a, s FROM ' . DDC1400Article::class . ' a JOIN a.userStates s WITH s.user = :activeUser')
                  ->setParameter('activeUser', $user1)
                  ->getResult();

        $queryCount = $this->getCurrentQueryCount();

        $this->_em->flush();

        self::assertSame($queryCount, $this->getCurrentQueryCount(), 'No query should be executed during flush in this case');
    }
}

/**
 * @Entity
 */
class DDC1400Article
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC1400UserState>
     * @OneToMany(targetEntity="DDC1400UserState", mappedBy="article", indexBy="userId", fetch="EXTRA_LAZY")
     */
    public $userStates;
}

/**
 * @Entity
 */
class DDC1400User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC1400UserState>
     * @OneToMany(targetEntity="DDC1400UserState", mappedBy="user", indexBy="articleId", fetch="EXTRA_LAZY")
     */
    public $userStates;
}

/**
 * @Entity
 */
class DDC1400UserState
{
    /**
     * @var DDC1400Article
     * @Id
     * @ManyToOne(targetEntity="DDC1400Article", inversedBy="userStates")
     */
    public $article;

    /**
     * @var DDC1400User
     * @Id
     * @ManyToOne(targetEntity="DDC1400User", inversedBy="userStates")
     */
    public $user;

    /**
     * @var int
     * @Column(name="user_id", type="integer")
     */
    public $userId;

    /**
     * @var int
     * @Column(name="article_id", type="integer")
     */
    public $articleId;
}
