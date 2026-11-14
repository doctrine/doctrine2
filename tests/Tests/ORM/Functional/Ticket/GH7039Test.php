<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Pagination\User;
use Doctrine\Tests\Models\Pagination\User1;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function iterator_to_array;

final class GH7039Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('company');
        $this->useModelSet('pagination');

        parent::setUp();

        $employee = new CompanyEmployee();
        $employee->setName('emp');
        $employee->setSalary(1000);
        $employee->setDepartment('IT');
        $employee->setStartDate(new DateTime());

        $manager = new CompanyManager();
        $manager->setName('mgr');
        $manager->setSalary(2000);
        $manager->setDepartment('IT');
        $manager->setStartDate(new DateTime());
        $manager->setTitle('boss');

        $user        = new User1();
        $user->name  = 'foo';
        $user->email = 'foo@example.com';

        $this->_em->persist($employee);
        $this->_em->persist($manager);
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();
    }

    /** @return array<string, array{class-string, class-string, bool}> */
    public static function provideInheritanceAndWalkers(): array
    {
        return [
            'JOINED, output walkers' => [CompanyPerson::class, CompanyManager::class, true],
            'JOINED, tree walkers' => [CompanyPerson::class, CompanyManager::class, false],
            'SINGLE_TABLE, output walkers' => [User::class, User1::class, true],
            'SINGLE_TABLE, tree walkers' => [User::class, User1::class, false],
        ];
    }

    /**
     * @param class-string $rootClass
     * @param class-string $subClass
     */
    #[DataProvider('provideInheritanceAndWalkers')]
    public function testCountWithInstanceOfParameter(string $rootClass, string $subClass, bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT e FROM ' . $rootClass . ' e WHERE e INSTANCE OF :type',
        );
        $query->setParameter('type', $this->_em->getClassMetadata($subClass));

        $paginator = new OffsetPaginator(true, $useOutputWalkers);
        $page      = $paginator->paginate($query, new Window(0, 10));

        self::assertCount(1, iterator_to_array($page->getIterator()));
        self::assertSame(1, $page->getTotalCount());
        self::assertSame(1, $paginator->getTotalCount($query));
    }
}
