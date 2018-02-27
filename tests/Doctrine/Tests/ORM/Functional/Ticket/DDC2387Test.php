<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\ORM\Functional\DatabaseDriverTestCase;
use Doctrine\ORM\Mapping\ClassMetadata;

class DDC2387Test extends DatabaseDriverTestCase
{
    /**
     * @group DDC-2387
     */
    public function testCompositeAssociationKeyDetection()
    {
        $product = new \Doctrine\DBAL\Schema\Table('ddc2387_product');
        $product->addColumn('id', 'integer');
        $product->setPrimaryKey(['id']);

        $attributes = new \Doctrine\DBAL\Schema\Table('ddc2387_attributes');
        $attributes->addColumn('product_id', 'integer');
        $attributes->addColumn('attribute_name', 'string');
        $attributes->setPrimaryKey(['product_id', 'attribute_name']);
        $attributes->addForeignKeyConstraint('ddc2387_product', ['product_id'], ['product_id']);

        $metadata = $this->convertToClassMetadata([$product, $attributes], []);

        $this->assertEquals(ClassMetadata::GENERATOR_TYPE_NONE, $metadata['Ddc2387Attributes']->generatorType);
        $this->assertEquals(ClassMetadata::GENERATOR_TYPE_AUTO, $metadata['Ddc2387Product']->generatorType);
    }
}
