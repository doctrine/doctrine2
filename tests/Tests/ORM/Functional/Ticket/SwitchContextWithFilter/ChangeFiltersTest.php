<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilter;

use Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilter\Entity\Order;
use Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilter\Entity\User;
use Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilter\SQLFilter\CompanySQLFilter;

final class ChangeFiltersTest extends AbstractTestCase
{
    private const string COMPANY_A = 'A';
    private const string COMPANY_B = 'B';

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            Order::class,
            User::class,
        ]);
    }

    /** @return non-empty-array<"companyA"|"companyB", array{orderId: int, userId: int}> */
    private function prepareData(): array
    {
        $user1  = new User(self::COMPANY_A);
        $order1 = new Order($user1);
        $user2  = new User(self::COMPANY_B);
        $order2 = new Order($user2);

        $this->_em->persist($user1);
        $this->_em->persist($order1);
        $this->_em->persist($user2);
        $this->_em->persist($order2);
        $this->_em->flush();
        $this->_em->clear();

        return [
            'companyA' => ['orderId' => $order1->id, 'userId' => $user1->id],
            'companyB' => ['orderId' => $order2->id, 'userId' => $user2->id],
        ];
    }

    public function testUseEnableDisableFilter(): void
    {
        $this->_em->getConfiguration()->addFilter(CompanySQLFilter::class, CompanySQLFilter::class);
        $this->_em->getFilters()->enable(CompanySQLFilter::class)->setParameter('company', self::COMPANY_A);

        ['companyA' => $companyA, 'companyB' => $companyB] = $this->prepareData();

        $order1 = $this->_em->find(Order::class, $companyA['orderId']);

        self::assertNotNull($order1->user, $this->generateMessage('Order1->User1 not found'));
        self::assertEquals($companyA['userId'], $order1->user->id, $this->generateMessage('Order1->User1 != User1'));

        $this->_em->getFilters()->disable(CompanySQLFilter::class);
        $this->_em->getFilters()->enable(CompanySQLFilter::class)->setParameter('company', self::COMPANY_B);

        $order2 = $this->_em->find(Order::class, $companyB['orderId']);

        self::assertNotNull($order2->user, $this->generateMessage('Order2->User2 not found'));
        self::assertEquals($companyB['userId'], $order2->user->id, $this->generateMessage('Order2->User2 != User2'));
    }

    public function testUseChangeFilterParameters(): void
    {
        $this->_em->getConfiguration()->addFilter(CompanySQLFilter::class, CompanySQLFilter::class);
        $filter = $this->_em->getFilters()->enable(CompanySQLFilter::class);

        ['companyA' => $companyA, 'companyB' => $companyB] = $this->prepareData();

        $filter->setParameter('company', self::COMPANY_A);

        $order1 = $this->_em->find(Order::class, $companyA['orderId']);

        self::assertNotNull($order1->user, $this->generateMessage('Order1->User1 not found'));
        self::assertEquals($companyA['userId'], $order1->user->id, $this->generateMessage('Order1->User1 != User1'));

        $filter->setParameter('company', self::COMPANY_B);

        $order2 = $this->_em->find(Order::class, $companyB['orderId']);

        self::assertNotNull($order2->user, $this->generateMessage('Order2->User2 not found'));
        self::assertEquals($companyB['userId'], $order2->user->id, $this->generateMessage('Order2->User2 != User2'));
    }

    public function testUseQueryBuilder(): void
    {
        $this->_em->getConfiguration()->addFilter(CompanySQLFilter::class, CompanySQLFilter::class);
        $filter = $this->_em->getFilters()->enable(CompanySQLFilter::class);

        ['companyA' => $companyA, 'companyB' => $companyB] = $this->prepareData();

        $getOrderByIdCache = function (int $orderId): Order|null {
            return $this->_em->createQueryBuilder()
                ->select('orderMaster, user')
                ->from(Order::class, 'orderMaster')
                ->innerJoin('orderMaster.user', 'user')
                ->where('orderMaster.id = :orderId')
                ->setParameter('orderId', $orderId)
                ->setCacheable(true)
                ->getQuery()
                ->setQueryCacheLifetime(10)
                ->getOneOrNullResult();
        };

        $filter->setParameter('company', self::COMPANY_A);

        $order = $getOrderByIdCache($companyB['orderId']);
        self::assertNull($order);

        $order = $getOrderByIdCache($companyA['orderId']);

        self::assertInstanceOf(Order::class, $order);
        self::assertInstanceOf(User::class, $order->user);
        self::assertEquals($companyA['userId'], $order->user->id);

        $filter->setParameter('company', self::COMPANY_B);

        $order = $getOrderByIdCache($companyA['orderId']);
        self::assertNull($order);

        $order = $getOrderByIdCache($companyB['orderId']);

        self::assertInstanceOf(Order::class, $order);
        self::assertInstanceOf(User::class, $order->user);
        self::assertEquals($companyB['userId'], $order->user->id);
    }
}
