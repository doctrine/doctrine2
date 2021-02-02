<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CompositeKeyRelations\CustomerClass;
use Doctrine\Tests\Models\CompositeKeyRelations\InvoiceClass;
use Doctrine\Tests\OrmFunctionalTestCase;

class CompositeKeyRelationsTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('compositekeyrelations');
        parent::setUp();
    }

    public function testFindEntityWithNotNullRelation(): void
    {
        $this->_em->getConnection()->insert('CustomerClass', [
            'companyCode' => 'AA',
            'code' => 'CUST1',
            'name' => 'Customer 1',
        ]);

        $this->_em->getConnection()->insert('InvoiceClass', [
            'companyCode' => 'AA',
            'invoiceNumber' => 'INV1',
            'customerCode' => 'CUST1',
        ]);

        $entity = $this->findEntity('AA', 'INV1');
        self::assertSame('AA', $entity->companyCode);
        self::assertSame('INV1', $entity->invoiceNumber);
        self::assertInstanceOf(CustomerClass::class, $entity->customer);
        self::assertSame('Customer 1', $entity->customer->name);
    }

    public function testFindEntityWithNullRelation(): void
    {
        $this->_em->getConnection()->insert('InvoiceClass', [
            'companyCode' => 'BB',
            'invoiceNumber' => 'INV1',
        ]);

        $entity = $this->findEntity('BB', 'INV1');
        self::assertSame('BB', $entity->companyCode);
        self::assertSame('INV1', $entity->invoiceNumber);
        self::assertNull($entity->customer);
    }

    private function findEntity(string $companyCode, string $invoiceNumber): InvoiceClass
    {
        return $this->_em->find(
            InvoiceClass::class,
            ['companyCode' => $companyCode, 'invoiceNumber' => $invoiceNumber]
        );
    }
}
