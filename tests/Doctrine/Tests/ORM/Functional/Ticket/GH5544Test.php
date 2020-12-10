<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group 5544
 */
class GH5544Test extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(GH5544User::class),
            $this->_em->getClassMetadata(GH5544UserBrowser::class),
            $this->_em->getClassMetadata(GH5544BrowserGroup::class),
        ]);
    }

    public function testIssue() : void
    {
        $this->createScalarIdentifierData();
        $this->createEntityIdentifierData();
        $initialQueryCount = $this->getCurrentQueryCount();

        $query = $this->createScalarIdentifierQuery(true);
        self::assertSame(2, (int) $query->getSingleScalarResult());
        self::assertEquals($initialQueryCount + 1, $this->getCurrentQueryCount());

        $query = $this->createScalarIdentifierQuery(false);
        self::assertSame(2, (int) $query->getSingleScalarResult());
        self::assertEquals($initialQueryCount + 2, $this->getCurrentQueryCount());

        $query = $this->createEntityIdentifierQuery(true);
        self::assertSame(2, (int) $query->getSingleScalarResult());
        self::assertEquals($initialQueryCount + 3, $this->getCurrentQueryCount());

        $query = $this->createEntityIdentifierQuery(false);
        self::assertSame(2, (int) $query->getSingleScalarResult());
        self::assertEquals($initialQueryCount + 4, $this->getCurrentQueryCount());
    }

    private function createScalarIdentifierQuery(bool $distinct) : Query
    {
        return $this->_em
            ->createQueryBuilder()
            ->select(\sprintf(
                'COUNT(%s CONCAT(bg.os, :concat_separator, bg.browser)) cnt',
                $distinct ? 'DISTINCT' : ''
            ))
            ->from(GH5544BrowserGroup::class, 'bg')
            ->setParameter('concat_separator', '|')
            ->getQuery();
    }

    private function createScalarIdentifierData() : void
    {
        $this->_em->persist(new GH5544BrowserGroup('Windows', 'Google Chrome', '80.0.3987.122'));
        $this->_em->persist(new GH5544BrowserGroup('Ubuntu', 'Mozilla FireFox', '73.0.1'));
        $this->_em->flush();
        $this->_em->clear();
    }

    private function createEntityIdentifierQuery(bool $distinct) : Query
    {
        return $this->_em
            ->createQueryBuilder()
            ->select(\sprintf(
                'COUNT(%s CONCAT(ub.user.id, :concat_separator, ub.browser)) cnt',
                $distinct ? 'DISTINCT' : ''
            ))
            ->from(GH5544UserBrowser::class, 'ub')
            ->setParameter('concat_separator', '|')
            ->getQuery();
    }

    private function createEntityIdentifierData() : void
    {
        $user = new GH5544User(12345);
        $this->_em->persist($user);
        $this->_em->persist(new GH5544UserBrowser($user, 'Google Chrome'));
        $this->_em->persist(new GH5544UserBrowser($user, 'Mozilla FireFox'));
        $this->_em->flush();
        $this->_em->clear();
    }
}

/**
 * @Entity
 * @Table(name="GH5544_user")
 */
class GH5544User
{
    /**
     * @Id
     * @GeneratedValue("NONE")
     * @Column(type="integer")
     */
    public $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

}

/**
 * @Entity
 * @Table(name="GH5544_user_browser")
 */
class GH5544UserBrowser
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue("NONE")
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(referencedColumnName="id", nullable=false)
     */
    public $user;

    /**
     * @Id
     * @GeneratedValue("NONE")
     * @Column(type="string", length=64)
     */
    public $browser;

    public function __construct(GH5544User $user, string $browser)
    {
        $this->user = $user;
        $this->browser = $browser;
    }
}

/**
 * @Entity
 * @Table(name="GH5544_browser_group")
 */
class GH5544BrowserGroup
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue("NONE")
     * @Column(type="string", length=64)
     */
    public $os;

    /**
     * @Id
     * @GeneratedValue("NONE")
     * @Column(type="string", length=64)
     */
    public $browser;

    /**
     * @Column(type="string", length=64)
     */
    public $version;

    public function __construct(string $os, string $browser, string $version)
    {
        $this->os = $os;
        $this->browser = $browser;
        $this->version = $version;
    }
}
