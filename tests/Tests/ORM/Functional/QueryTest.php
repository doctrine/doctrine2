<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\UnexpectedResultException;
use Doctrine\Tests\IterableTester;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Enums\AccessLevel;
use Doctrine\Tests\Models\Enums\UserStatus;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function count;
use function iterator_to_array;

/**
 * Functional Query tests.
 */
class QueryTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function testSimpleQueries(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery("select u, upper(u.name) from Doctrine\Tests\Models\CMS\CmsUser u where u.username = 'gblanco'");

        $result = $query->getResult();

        self::assertCount(1, $result);
        self::assertInstanceOf(CmsUser::class, $result[0][0]);
        self::assertEquals('Guilherme', $result[0][0]->name);
        self::assertEquals('gblanco', $result[0][0]->username);
        self::assertEquals('developer', $result[0][0]->status);
        self::assertEquals('GUILHERME', $result[0][1]);

        $resultArray = $query->getArrayResult();
        self::assertCount(1, $resultArray);
        self::assertIsArray($resultArray[0][0]);
        self::assertEquals('Guilherme', $resultArray[0][0]['name']);
        self::assertEquals('gblanco', $resultArray[0][0]['username']);
        self::assertEquals('developer', $resultArray[0][0]['status']);
        self::assertEquals('GUILHERME', $resultArray[0][1]);

        $scalarResult = $query->getScalarResult();
        self::assertCount(1, $scalarResult);
        self::assertEquals('Guilherme', $scalarResult[0]['u_name']);
        self::assertEquals('gblanco', $scalarResult[0]['u_username']);
        self::assertEquals('developer', $scalarResult[0]['u_status']);
        self::assertEquals('GUILHERME', $scalarResult[0][1]);

        $query = $this->_em->createQuery("select upper(u.name) from Doctrine\Tests\Models\CMS\CmsUser u where u.username = 'gblanco'");
        self::assertEquals('GUILHERME', $query->getSingleScalarResult());
    }

    public function testJoinQueries(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $user->addArticle($article1);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $user->addArticle($article2);

        $this->_em->persist($user);
        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select u, a from ' . CmsUser::class . ' u join u.articles a ORDER BY a.topic');
        $users = $query->getResult();
        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertCount(2, $users[0]->articles);
        self::assertEquals('Doctrine 2', $users[0]->articles[0]->topic);
        self::assertEquals('Symfony 2', $users[0]->articles[1]->topic);
    }

    public function testJoinPartialObjectHydration(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $user->addArticle($article1);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $user->addArticle($article2);

        $this->_em->persist($user);
        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select partial u.{id, username}, partial a.{id, topic} from ' . CmsUser::class . ' u join u.articles a ORDER BY a.topic');
        $users = $query->getResult();

        $queries          = count($this->getQueryLog()->queries);
        $topicsByUsername = [];
        foreach ($users as $user) {
            $topicsByUsername[$user->username] = $user->articles->map(static fn ($article) => $article->topic)->toArray();
        }

        self::assertQueryCount($queries);
        self::assertEquals(['gblanco' => ['Doctrine 2', 'Symfony 2']], $topicsByUsername);

        $userNames = [];
        foreach ($users as $user) {
            $userNames[] = $user->name;
        }

        self::assertQueryCount($queries + 1);
        self::assertEquals(['Guilherme'], $userNames);
    }

    public function testJoinPartialArrayHydration(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $user->addArticle($article1);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $user->addArticle($article2);

        $this->_em->persist($user);
        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select partial u.{id, username}, partial a.{id, topic} from ' . CmsUser::class . ' u join u.articles a ORDER BY a.topic');
        $users = $query->getArrayResult();

        $this->assertEquals([
            [
                'id' => $user->id,
                'username' => 'gblanco',
                'articles' =>
                [
                    [
                        'id' => $article1->id,
                        'topic' => 'Doctrine 2',
                    ],
                    [
                        'id' => $article2->id,
                        'topic' => 'Symfony 2',
                    ],
                ],
            ],
        ], $users);
    }

    public function testUsingZeroBasedQueryParameterShouldWork(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Jonathan';
        $user->username = 'jwage';
        $user->status   = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $q = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.username = ?0');
        $q->setParameter(0, 'jwage');
        $user = $q->getSingleResult();

        self::assertNotNull($user);
    }

    public function testUsingUnknownQueryParameterShouldThrowException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid parameter: token 2 is not defined in the query.');

        $q = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?1');
        $q->setParameter(2, 'jwage');
        $user = $q->getSingleResult();
    }

    public function testTooManyParametersShouldThrowException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Too many parameters: the query defines 1 parameters and you bound 2');

        $q = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?1');
        $q->setParameter(1, 'jwage');
        $q->setParameter(2, 'jwage');

        $user = $q->getSingleResult();
    }

    public function testTooFewParametersShouldThrowException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Too few parameters: the query defines 1 parameters but you only bound 0');

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?1')
                  ->getSingleResult();
    }

    public function testInvalidInputParameterThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?')
                  ->setParameter(1, 'jwage')
                  ->getSingleResult();
    }

    public function testUseStringEnumCaseAsParameter(): void
    {
        $user           = new CmsUser();
        $user->name     = 'John';
        $user->username = 'john';
        $user->status   = 'inactive';
        $this->_em->persist($user);

        $user           = new CmsUser();
        $user->name     = 'Jane';
        $user->username = 'jane';
        $user->status   = 'active';
        $this->_em->persist($user);

        unset($user);

        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.status = :status')
            ->setParameter('status', UserStatus::Active)
            ->getResult();

        self::assertCount(1, $result);
        self::assertSame('jane', $result[0]->username);
    }

    public function testUseIntegerEnumCaseAsParameter(): void
    {
        $user           = new CmsUser();
        $user->name     = 'John';
        $user->username = 'john';
        $user->status   = '1';
        $this->_em->persist($user);

        $user           = new CmsUser();
        $user->name     = 'Jane';
        $user->username = 'jane';
        $user->status   = '2';
        $this->_em->persist($user);

        unset($user);

        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.status = :status')
            ->setParameter('status', AccessLevel::User)
            ->getResult();

        self::assertCount(1, $result);
        self::assertSame('jane', $result[0]->username);
    }

    public function testSetParameters(): void
    {
        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'jwage'));
        $parameters->add(new Parameter(2, 'active'));

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?1 AND u.status = ?2')
                  ->setParameters($parameters)
                  ->getResult();

        self::assertSame(
            [1 => 'jwage', 2 => 'active'],
            $this->getLastLoggedQuery()['params'],
        );
    }

    public function testSetParametersBackwardsCompatible(): void
    {
        $parameters = [1 => 'jwage', 2 => 'active'];

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u WHERE u.name = ?1 AND u.status = ?2')
                  ->setParameters($parameters)
                  ->getResult();

        self::assertSame($parameters, $this->getLastLoggedQuery()['params']);
    }

    #[Group('DDC-1070')]
    public function testIterateResultAsArrayAndParams(): void
    {
        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();
        $articleId = $article1->id;

        $query = $this->_em->createQuery('select a from ' . CmsArticle::class . ' a WHERE a.topic = ?1');

        $expectedArticle = [
            'id'      => $articleId,
            'topic'   => 'Doctrine 2',
            'text'    => 'This is an introduction to Doctrine 2.',
            'version' => 1,
        ];

        $articles = $query->toIterable(
            new ArrayCollection([new Parameter(1, 'Doctrine 2')]),
            Query::HYDRATE_ARRAY,
        );

        $articles = IterableTester::iterableToArray($articles);

        self::assertCount(1, $articles);
        self::assertEquals([$expectedArticle], $articles);
    }

    public function testIterateResultIterativelyBuildUpUnitOfWork(): void
    {
        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $query    = $this->_em->createQuery('select a from ' . CmsArticle::class . ' a');
        $articles = $query->toIterable();

        $iteratedCount = 0;
        $topics        = [];

        foreach ($articles as $article) {
            $topics[] = $article->topic;

            $identityMap      = $this->_em->getUnitOfWork()->getIdentityMap();
            $identityMapCount = count($identityMap[CmsArticle::class]);
            self::assertGreaterThan($iteratedCount, $identityMapCount);

            $iteratedCount++;
        }

        self::assertSame(['Doctrine 2', 'Symfony 2'], $topics);
        self::assertSame(2, $iteratedCount);
    }

    public function testToIterableWithMultipleSelectElements(): void
    {
        $author           = new CmsUser();
        $author->name     = 'Ben';
        $author->username = 'beberlei';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $article1->setAuthor($author);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $article2->setAuthor($author);

        $this->_em->persist($article1);
        $this->_em->persist($article2);
        $this->_em->persist($author);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select a, u from ' . CmsArticle::class . ' a JOIN ' . CmsUser::class . ' u ON a.user = u');

        $result = iterator_to_array($query->toIterable());

        self::assertCount(2, $result);

        foreach ($result as $row) {
            self::assertCount(2, $row);
            self::assertInstanceOf(CmsArticle::class, $row[0]);
            self::assertInstanceOf(CmsUser::class, $row[1]);
        }
    }

    public function testToIterableWithMixedResultEntityScalars(): void
    {
        $author           = new CmsUser();
        $author->name     = 'Ben';
        $author->username = 'beberlei';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $article1->setAuthor($author);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $article2->setAuthor($author);

        $article3        = new CmsArticle();
        $article3->topic = 'lala 2';
        $article3->text  = 'This is an introduction to Symfony 2.';
        $article3->setAuthor($author);

        $this->_em->persist($article1);
        $this->_em->persist($article2);
        $this->_em->persist($article3);
        $this->_em->persist($author);

        $this->_em->flush();
        $this->_em->clear();

        $query  = $this->_em->createQuery(
            'select a, a.topic, a.text from ' . CmsArticle::class . ' a order by a.id asc',
        );
        $result = $query->toIterable();

        $it = iterator_to_array($result);
        $this->assertCount(3, $it);
        $this->assertEquals('Doctrine 2', $it[0]['topic']);
        $this->assertEquals('Doctrine 2', $it[0][0]->topic);
        $this->assertEquals('lala 2', $it[2]['topic']);
        $this->assertEquals('lala 2', $it[2][0]->topic);
    }

    public function testToIterableWithMixedResultArbitraryJoinsScalars(): void
    {
        $author           = new CmsUser();
        $author->name     = 'Ben';
        $author->username = 'beberlei';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $article1->setAuthor($author);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $article2->setAuthor($author);

        $article3        = new CmsArticle();
        $article3->topic = 'lala 2';
        $article3->text  = 'This is an introduction to Symfony 2.';
        $article3->setAuthor($author);

        $this->_em->persist($article1);
        $this->_em->persist($article2);
        $this->_em->persist($article3);
        $this->_em->persist($author);

        $this->_em->flush();
        $this->_em->clear();

        $query  = $this->_em->createQuery(
            'select a, u, a.topic, a.text from ' . CmsArticle::class . ' a, ' . CmsUser::class . ' u WHERE a.user = u order by a.id asc',
        );
        $result = $query->toIterable();

        $it = iterator_to_array($result);

        $this->assertCount(3, $it);
        $this->assertEquals('Doctrine 2', $it[0]['topic']);
        $this->assertEquals('Doctrine 2', $it[0][0]->topic);
        $this->assertEquals('beberlei', $it[0][1]->username);
        $this->assertEquals('lala 2', $it[2]['topic']);
        $this->assertEquals('lala 2', $it[2][0]->topic);
        $this->assertEquals('beberlei', $it[2][1]->username);
    }

    public function testToIterableWithMixedResultScalarsOnly(): void
    {
        $author           = new CmsUser();
        $author->name     = 'Ben';
        $author->username = 'beberlei';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $article1->setAuthor($author);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $article2->setAuthor($author);

        $article3        = new CmsArticle();
        $article3->topic = 'lala 2';
        $article3->text  = 'This is an introduction to Symfony 2.';
        $article3->setAuthor($author);

        $this->_em->persist($article1);
        $this->_em->persist($article2);
        $this->_em->persist($article3);
        $this->_em->persist($author);

        $this->_em->flush();
        $this->_em->clear();

        $query  = $this->_em->createQuery(
            'select a.topic, a.text from ' . CmsArticle::class . ' a order by a.id asc',
        );
        $result = $query->toIterable();

        $it = iterator_to_array($result);

        $this->assertEquals([
            ['topic' => 'Doctrine 2', 'text' => 'This is an introduction to Doctrine 2.'],
            ['topic' => 'Symfony 2', 'text' => 'This is an introduction to Symfony 2.'],
            ['topic' => 'lala 2', 'text' => 'This is an introduction to Symfony 2.'],
        ], $it);
    }

    public function testIterateResultClearEveryCycle(): void
    {
        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery(
            'select a from Doctrine\Tests\Models\CMS\CmsArticle a order by a.id asc',
        );

        $articles      = $query->toIterable();
        $iteratedCount = 0;
        $topics        = [];
        foreach ($articles as $article) {
            $topics[] = $article->topic;

            $this->_em->clear();

            $iteratedCount++;
        }

        self::assertSame(['Doctrine 2', 'Symfony 2'], $topics);
        self::assertSame(2, $iteratedCount);

        $this->_em->flush();
    }

    public function testToIterableResultFetchJoinedCollectionThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $query = $this->_em->createQuery("SELECT u, a FROM ' . CmsUser::class . ' u JOIN u.articles a");
        $query->toIterable();
    }

    public function testGetSingleResultThrowsExceptionOnNoResult(): void
    {
        $this->expectException(NoResultException::class);
        $this->_em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a')
             ->getSingleResult();
    }

    public function testGetSingleScalarResultThrowsExceptionOnNoResult(): void
    {
        $this->expectException(NoResultException::class);
        $this->_em->createQuery('select a.id from Doctrine\Tests\Models\CMS\CmsArticle a')
             ->getSingleScalarResult();
    }

    public function testGetSingleScalarResultThrowsExceptionOnSingleRowWithMultipleColumns(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Javier';
        $user->username = 'phansys';
        $user->status   = 'developer';

        $this->_em->persist($user);

        $this->_em->flush();
        $this->_em->clear();

        $this->expectException(NonUniqueResultException::class);
        $this->expectExceptionMessage(
            'The query returned a row containing multiple columns. Change the query or use a different result function'
            . ' like getScalarResult().',
        );

        $this->_em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }

    public function testGetSingleScalarResultThrowsExceptionOnNonUniqueResult(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';

        $article1        = new CmsArticle();
        $article1->topic = 'Doctrine 2';
        $article1->text  = 'This is an introduction to Doctrine 2.';
        $user->addArticle($article1);

        $article2        = new CmsArticle();
        $article2->topic = 'Symfony 2';
        $article2->text  = 'This is an introduction to Symfony 2.';
        $user->addArticle($article2);

        $this->_em->persist($user);
        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->flush();
        $this->_em->clear();

        $this->expectException(NonUniqueResultException::class);
        $this->expectExceptionMessage(
            'The query returned multiple rows. Change the query or use a different result function like getScalarResult().',
        );

        $this->_em->createQuery('select a.id from Doctrine\Tests\Models\CMS\CmsArticle a')
             ->getSingleScalarResult();
    }

    public function testModifiedLimitQuery(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $user           = new CmsUser();
            $user->name     = 'Guilherme' . $i;
            $user->username = 'gblanco' . $i;
            $user->status   = 'developer';
            $this->_em->persist($user);
        }

        $this->_em->flush();
        $this->_em->clear();

        $query = 'SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.username';

        $data = $this->_em->createQuery($query)
                  ->setFirstResult(1)
                  ->setMaxResults(2)
                  ->getResult();

        self::assertCount(2, $data);
        self::assertEquals('gblanco1', $data[0]->username);
        self::assertEquals('gblanco2', $data[1]->username);

        $data = $this->_em->createQuery($query)
                  ->setFirstResult(3)
                  ->setMaxResults(2)
                  ->getResult();

        self::assertCount(2, $data);
        self::assertEquals('gblanco3', $data[0]->username);
        self::assertEquals('gblanco4', $data[1]->username);

        $data = $this->_em->createQuery($query)
                  ->setFirstResult(3)
                  ->setMaxResults(2)
                  ->getScalarResult();
    }

    #[Group('DDC-604')]
    public function testEntityParameters(): void
    {
        $article          = new CmsArticle();
        $article->topic   = 'dr. dolittle';
        $article->text    = 'Once upon a time ...';
        $author           = new CmsUser();
        $author->name     = 'anonymous';
        $author->username = 'anon';
        $author->status   = 'here';
        $article->user    = $author;
        $this->_em->persist($author);
        $this->_em->persist($article);
        $this->_em->flush();
        $this->_em->clear();

        $q = $this->_em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a where a.topic = :topic and a.user = :user')
                ->setParameter('user', $this->_em->getReference(CmsUser::class, $author->id))
                ->setParameter('topic', 'dr. dolittle');

        $result = $q->getResult();
        self::assertEquals(1, count($result));
        self::assertInstanceOf(CmsArticle::class, $result[0]);
        self::assertEquals('dr. dolittle', $result[0]->topic);
        self::assertTrue($this->isUninitializedObject($result[0]->user));
    }

    #[Group('DDC-952')]
    public function testEnableFetchEagerMode(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $article          = new CmsArticle();
            $article->topic   = 'dr. dolittle';
            $article->text    = 'Once upon a time ...';
            $author           = new CmsUser();
            $author->name     = 'anonymous';
            $author->username = 'anon' . $i;
            $author->status   = 'here';
            $article->user    = $author;
            $this->_em->persist($author);
            $this->_em->persist($article);
        }

        $this->_em->flush();
        $this->_em->clear();

        $articles = $this->_em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a')
                         ->setFetchMode(CmsArticle::class, 'user', ClassMetadata::FETCH_EAGER)
                         ->getResult();

        self::assertCount(10, $articles);
        foreach ($articles as $article) {
            self::assertFalse($this->isUninitializedObject($article));
        }
    }

    #[Group('DDC-991')]
    public function testgetOneOrNullResult(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select u from ' . CmsUser::class . " u where u.username = 'gblanco'");

        $fetchedUser = $query->getOneOrNullResult();
        self::assertInstanceOf(CmsUser::class, $fetchedUser);
        self::assertEquals('gblanco', $fetchedUser->username);

        $query           = $this->_em->createQuery('select u.username from ' . CmsUser::class . " u where u.username = 'gblanco'");
        $fetchedUsername = $query->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);
        self::assertEquals('gblanco', $fetchedUsername);
    }

    #[Group('DDC-991')]
    public function testgetOneOrNullResultSeveralRows(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Guilherme';
        $user->username = 'gblanco';
        $user->status   = 'developer';
        $this->_em->persist($user);
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u');

        $this->expectException(NonUniqueResultException::class);

        $fetchedUser = $query->getOneOrNullResult();
    }

    #[Group('DDC-991')]
    public function testgetOneOrNullResultNoRows(): void
    {
        $query = $this->_em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u');
        self::assertNull($query->getOneOrNullResult());

        $query = $this->_em->createQuery("select u.username from Doctrine\Tests\Models\CMS\CmsUser u where u.username = 'gblanco'");
        self::assertNull($query->getOneOrNullResult(Query::HYDRATE_SCALAR));
    }

    #[Group('DBAL-171')]
    public function testParameterOrder(): void
    {
        $user1           = new CmsUser();
        $user1->name     = 'Benjamin';
        $user1->username = 'beberlei';
        $user1->status   = 'developer';
        $this->_em->persist($user1);

        $user2           = new CmsUser();
        $user2->name     = 'Roman';
        $user2->username = 'romanb';
        $user2->status   = 'developer';
        $this->_em->persist($user2);

        $user3           = new CmsUser();
        $user3->name     = 'Jonathan';
        $user3->username = 'jwage';
        $user3->status   = 'developer';
        $this->_em->persist($user3);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.status = :a AND u.id IN (:b)');
        $query->setParameters(new ArrayCollection(
            [
                new Parameter('b', [$user1->id, $user2->id, $user3->id]),
                new Parameter('a', 'developer'),
            ],
        ));
        $result = $query->getResult();

        self::assertCount(3, $result);
    }

    public function testDqlWithAutoInferOfParameters(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Benjamin';
        $user->username = 'beberlei';
        $user->status   = 'developer';
        $this->_em->persist($user);

        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'developer';
        $this->_em->persist($user);

        $user           = new CmsUser();
        $user->name     = 'Jonathan';
        $user->username = 'jwage';
        $user->status   = 'developer';
        $this->_em->persist($user);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username IN (?0)');
        $query->setParameter(0, ['beberlei', 'jwage']);

        $users = $query->execute();

        self::assertCount(2, $users);
    }

    public function testQueryBuilderWithStringWhereClauseContainingOrAndConditionalPrimary(): void
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->innerJoin('u.articles', 'a')
           ->where('(u.id = 0) OR (u.id IS NULL)');

        $query = $qb->getQuery();
        $users = $query->execute();

        self::assertCount(0, $users);
    }

    public function testQueryWithArrayOfEntitiesAsParameter(): void
    {
        $userA           = new CmsUser();
        $userA->name     = 'Benjamin';
        $userA->username = 'beberlei';
        $userA->status   = 'developer';
        $this->_em->persist($userA);

        $userB           = new CmsUser();
        $userB->name     = 'Roman';
        $userB->username = 'romanb';
        $userB->status   = 'developer';
        $this->_em->persist($userB);

        $userC           = new CmsUser();
        $userC->name     = 'Jonathan';
        $userC->username = 'jwage';
        $userC->status   = 'developer';
        $this->_em->persist($userC);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u IN (?0) OR u.username = ?1');
        $query->setParameter(0, [$userA, $userC]);
        $query->setParameter(1, 'beberlei');

        $users = $query->execute();

        self::assertCount(2, $users);
    }

    public function testQueryWithHiddenAsSelectExpression(): void
    {
        $userA           = new CmsUser();
        $userA->name     = 'Benjamin';
        $userA->username = 'beberlei';
        $userA->status   = 'developer';
        $this->_em->persist($userA);

        $userB           = new CmsUser();
        $userB->name     = 'Roman';
        $userB->username = 'romanb';
        $userB->status   = 'developer';
        $this->_em->persist($userB);

        $userC           = new CmsUser();
        $userC->name     = 'Jonathan';
        $userC->username = 'jwage';
        $userC->status   = 'developer';
        $this->_em->persist($userC);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT u, (SELECT COUNT(u2.id) FROM Doctrine\Tests\Models\CMS\CmsUser u2) AS HIDDEN total FROM Doctrine\Tests\Models\CMS\CmsUser u');
        $users = $query->execute();

        self::assertCount(3, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
    }

    #[Group('DDC-1651')]
    public function testSetParameterBindingSingleIdentifierObject(): void
    {
        $userC           = new CmsUser();
        $userC->name     = 'Jonathan';
        $userC->username = 'jwage';
        $userC->status   = 'developer';
        $this->_em->persist($userC);

        $this->_em->flush();
        $this->_em->clear();

        $q = $this->_em->createQuery('SELECT DISTINCT u from Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1');
        $q->setParameter(1, $userC);

        self::assertEquals($userC, $q->getParameter(1)->getValue());

        // Parameter is not converted before, but it should be converted during execution. Test should not fail here
        $q->getResult();
    }

    #[Group('DDC-2319')]
    public function testSetCollectionParameterBindingSingleIdentifierObject(): void
    {
        $u1           = new CmsUser();
        $u1->name     = 'Name1';
        $u1->username = 'username1';
        $u1->status   = 'developer';
        $this->_em->persist($u1);

        $u2           = new CmsUser();
        $u2->name     = 'Name2';
        $u2->username = 'username2';
        $u2->status   = 'tester';
        $this->_em->persist($u2);

        $u3           = new CmsUser();
        $u3->name     = 'Name3';
        $u3->username = 'username3';
        $u3->status   = 'tester';
        $this->_em->persist($u3);

        $this->_em->flush();
        $this->_em->clear();

        $userCollection = new ArrayCollection();

        $userCollection->add($u1);
        $userCollection->add($u2);
        $userCollection->add($u3->getId());

        $q = $this->_em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u IN (:users) ORDER BY u.id');
        $q->setParameter('users', $userCollection);
        $users = $q->execute();

        self::assertCount(3, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertInstanceOf(CmsUser::class, $users[1]);
        self::assertInstanceOf(CmsUser::class, $users[2]);

        $resultUser1 = $users[0];
        $resultUser2 = $users[1];
        $resultUser3 = $users[2];

        self::assertEquals($u1->username, $resultUser1->username);
        self::assertEquals($u2->username, $resultUser2->username);
        self::assertEquals($u3->username, $resultUser3->username);
    }

    #[Group('DDC-1822')]
    public function testUnexpectedResultException(): void
    {
        $dql          = 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u';
        $u1           = new CmsUser();
        $u2           = new CmsUser();
        $u1->name     = 'Fabio B. Silva';
        $u1->username = 'FabioBatSilva';
        $u1->status   = 'developer';
        $u2->name     = 'Test';
        $u2->username = 'test';
        $u2->status   = 'tester';

        try {
            $this->_em->createQuery($dql)->getSingleResult();
            self::fail('Expected exception "\Doctrine\ORM\NoResultException".');
        } catch (UnexpectedResultException $exc) {
            self::assertInstanceOf(NoResultException::class, $exc);
        }

        $this->_em->persist($u1);
        $this->_em->persist($u2);
        $this->_em->flush();
        $this->_em->clear();

        try {
            $this->_em->createQuery($dql)->getSingleResult();
            self::fail('Expected exception "\Doctrine\ORM\NonUniqueResultException".');
        } catch (UnexpectedResultException $exc) {
            self::assertInstanceOf(NonUniqueResultException::class, $exc);
        }
    }

    public function testMultipleJoinComponentsUsingInnerJoin(): void
    {
        $userA           = new CmsUser();
        $userA->name     = 'Benjamin';
        $userA->username = 'beberlei';
        $userA->status   = 'developer';

        $phonenumberA              = new CmsPhonenumber();
        $phonenumberA->phonenumber = '111111';
        $userA->addPhonenumber($phonenumberA);

        $userB           = new CmsUser();
        $userB->name     = 'Alexander';
        $userB->username = 'asm89';
        $userB->status   = 'developer';

        $this->_em->persist($userA);
        $this->_em->persist($userB);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('
            SELECT u, p
              FROM Doctrine\Tests\Models\CMS\CmsUser u
             INNER JOIN Doctrine\Tests\Models\CMS\CmsPhonenumber p ON u = p.user
        ');
        $users = $query->execute();

        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertInstanceOf(CmsPhonenumber::class, $users[1]);
    }

    public function testMultipleJoinComponentsUsingLeftJoin(): void
    {
        $userA           = new CmsUser();
        $userA->name     = 'Benjamin';
        $userA->username = 'beberlei';
        $userA->status   = 'developer';

        $phonenumberA              = new CmsPhonenumber();
        $phonenumberA->phonenumber = '111111';
        $userA->addPhonenumber($phonenumberA);

        $userB           = new CmsUser();
        $userB->name     = 'Alexander';
        $userB->username = 'asm89';
        $userB->status   = 'developer';

        $this->_em->persist($userA);
        $this->_em->persist($userB);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('
            SELECT u, p
              FROM Doctrine\Tests\Models\CMS\CmsUser u
              LEFT JOIN Doctrine\Tests\Models\CMS\CmsPhonenumber p ON u = p.user
        ');
        $users = $query->execute();

        self::assertCount(4, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertInstanceOf(CmsPhonenumber::class, $users[1]);
        self::assertInstanceOf(CmsUser::class, $users[2]);
        self::assertNull($users[3]);
    }
}
