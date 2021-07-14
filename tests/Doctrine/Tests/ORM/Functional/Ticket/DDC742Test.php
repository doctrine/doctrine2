<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function class_exists;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @group non-cacheable
 */
class DDC742Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        if (! class_exists(FilesystemCache::class)) {
            $this->markTestSkipped('Test only applies with doctrine/cache 1.x');
        }

        parent::setUp();

        $testDir = sys_get_temp_dir() . '/DDC742Test' . uniqid();

        mkdir($testDir);

        // using a Filesystemcache to ensure that the cached data is serialized
        $this->_em->getMetadataFactory()->setCacheDriver(new FilesystemCache($testDir));

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC742User::class),
                    $this->_em->getClassMetadata(DDC742Comment::class),
                ]
            );
        } catch (Exception $e) {
        }

        // make sure classes will be deserialized from caches
        $this->_em->getMetadataFactory()->setMetadataFor(DDC742User::class, null);
        $this->_em->getMetadataFactory()->setMetadataFor(DDC742Comment::class, null);
    }

    public function testIssue(): void
    {
        $user                   = new DDC742User();
        $user->title            = 'Foo';
        $user->favoriteComments = new ArrayCollection();

        $comment1          = new DDC742Comment();
        $comment1->content = 'foo';

        $comment2          = new DDC742Comment();
        $comment2->content = 'bar';

        $comment3          = new DDC742Comment();
        $comment3->content = 'baz';

        $user->favoriteComments->add($comment1);
        $user->favoriteComments->add($comment2);

        $this->_em->persist($user);
        $this->_em->persist($comment1);
        $this->_em->persist($comment2);
        $this->_em->persist($comment3);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find(DDC742User::class, $user->id);
        $user->favoriteComments->add($this->_em->find(DDC742Comment::class, $comment3->id));

        $this->_em->flush();
        $this->addToAssertionCount(1);
    }
}

/**
 * @Entity
 * @Table(name="ddc742_users")
 */
class DDC742User
{
    /**
     * User Id
     *
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Column(length=100, type="string")
     * @var string
     */
    public $title;

    /**
     * @ManyToMany(targetEntity="DDC742Comment", cascade={"persist"}, fetch="EAGER")
     * @JoinTable(
     *  name="user_comments",
     *  joinColumns={@JoinColumn(name="user_id",referencedColumnName="id")},
     *  inverseJoinColumns={@JoinColumn(name="comment_id", referencedColumnName="id")}
     * )
     * @var PersistentCollection
     */
    public $favoriteComments;
}

/**
 * @Entity
 * @Table(name="ddc742_comments")
 */
class DDC742Comment
{
    /**
     * User Id
     *
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Column(length=100, type="string")
     * @var string
     */
    public $content;
}
