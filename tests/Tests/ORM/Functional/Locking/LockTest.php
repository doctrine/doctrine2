<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Locking;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Group;

#[Group('locking')]
class LockTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    #[DoesNotPerformAssertions]
    public function testLockVersionedEntity(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->_em->lock($article, LockMode::OPTIMISTIC, $article->version);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockVersionedEntityMismatchThrowsException(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->expectException(OptimisticLockException::class);
        $this->_em->lock($article, LockMode::OPTIMISTIC, $article->version + 1);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockUnversionedEntityThrowsException(): void
    {
        $user           = new CmsUser();
        $user->name     = 'foo';
        $user->status   = 'active';
        $user->username = 'foo';

        $this->_em->persist($user);
        $this->_em->flush();

        $this->expectException(OptimisticLockException::class);

        $this->_em->lock($user, LockMode::OPTIMISTIC);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockUnmanagedEntityThrowsException(): void
    {
        $article = new CmsArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity ' . CmsArticle::class);

        $this->_em->lock($article, LockMode::OPTIMISTIC, $article->version + 1);
    }

    #[Group('locking')]
    public function testLockWithLockModeNoneDoesNotRequireTransaction(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->getQueryLog()->reset()->enable();

        $this->_em->lock($article, LockMode::NONE);

        $this->assertQueryCount(0);
    }

    #[Group('locking')]
    public function testFindWithLockModeNoneDoesNotRefreshManagedEntity(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $article->topic = 'Changed in memory';

        $this->getQueryLog()->reset()->enable();

        $found = $this->_em->find(CmsArticle::class, $article->id, LockMode::NONE);

        self::assertSame($article, $found);
        self::assertSame('Changed in memory', $found->topic);
        $this->assertQueryCount(0);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockPessimisticReadNoTransactionThrowsException(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->expectException(TransactionRequiredException::class);

        $this->_em->lock($article, LockMode::PESSIMISTIC_READ);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockPessimisticWriteNoTransactionThrowsException(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->expectException(TransactionRequiredException::class);

        $this->_em->lock($article, LockMode::PESSIMISTIC_WRITE);
    }

    #[Group('locking')]
    public function testRefreshWithLockPessimisticWriteNoTransactionThrowsException(): void
    {
        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->expectException(TransactionRequiredException::class);

        $this->_em->refresh($article, LockMode::PESSIMISTIC_WRITE);
    }

    #[Group('DDC-178')]
    #[Group('locking')]
    public function testLockPessimisticWrite(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Database Driver has no Write Lock support.');
        }

        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->_em->beginTransaction();
        try {
            $this->_em->lock($article, LockMode::PESSIMISTIC_WRITE);
            $this->_em->commit();
        } catch (Exception) {
            $this->_em->rollback();
        }

        $lastLoggedQuery = $this->getLastLoggedQuery()['sql'];
        // DBAL 2 logs a commit as last query.
        if ($lastLoggedQuery === '"COMMIT"') {
            $lastLoggedQuery = $this->getLastLoggedQuery(1)['sql'];
        }

        self::assertStringContainsString('FOR UPDATE', $lastLoggedQuery);
    }

    #[Group('locking')]
    public function testRefreshWithLockPessimisticWrite(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Database Driver has no Write Lock support.');
        }

        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->_em->beginTransaction();
        try {
            $this->_em->refresh($article, LockMode::PESSIMISTIC_WRITE);
            $this->_em->commit();
        } catch (Exception) {
            $this->_em->rollback();
        }

        $lastLoggedQuery = $this->getLastLoggedQuery()['sql'];
        // DBAL 2 logs a commit as last query.
        if ($lastLoggedQuery === '"COMMIT"') {
            $lastLoggedQuery = $this->getLastLoggedQuery(1)['sql'];
        }

        self::assertStringContainsString('FOR UPDATE', $lastLoggedQuery);
    }

    #[Group('DDC-178')]
    public function testLockPessimisticRead(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Database Driver has no Write Lock support.');
        }

        $article        = new CmsArticle();
        $article->text  = 'my article';
        $article->topic = 'Hello';

        $this->_em->persist($article);
        $this->_em->flush();

        $this->_em->beginTransaction();

        try {
            $this->_em->lock($article, LockMode::PESSIMISTIC_READ);
            $this->_em->commit();
        } catch (Exception) {
            $this->_em->rollback();
        }

        $lastLoggedQuery = $this->getLastLoggedQuery()['sql'];
        // DBAL 2 logs a commit as last query.
        if ($lastLoggedQuery === '"COMMIT"') {
            $lastLoggedQuery = $this->getLastLoggedQuery(1)['sql'];
        }

        self::assertThat($lastLoggedQuery, self::logicalOr(
            self::stringContains('FOR UPDATE'),
            self::stringContains('FOR SHARE'),
            self::stringContains('LOCK IN SHARE MODE'),
        ));
    }

    #[Group('DDC-1693')]
    public function testLockOptimisticNonVersionedThrowsExceptionInDQL(): void
    {
        $dql = 'SELECT u FROM ' . CmsUser::class . " u WHERE u.username = 'gblanco'";

        $this->expectException(OptimisticLockException::class);
        $this->expectExceptionMessage('The optimistic lock on an entity failed.');

        $this->_em->createQuery($dql)
                  ->setHint(Query::HINT_LOCK_MODE, LockMode::OPTIMISTIC)
                  ->getSQL();
    }
}
