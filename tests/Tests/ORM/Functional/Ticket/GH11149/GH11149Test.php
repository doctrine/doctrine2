<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH11149;

use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\OrmFunctionalTestCase;
use ReflectionClass;

class GH11149Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            Locale::class,
            EagerProduct::class,
            EagerProductTranslation::class,
        ]);
    }

    public function testFetchEagerModeWithIndexBy(): void
    {
        // Load entities into database
        $this->_em->persist($product = new EagerProduct(11149));
        $this->_em->persist($locale = new Locale('fr_FR'));
        $this->_em->persist(new EagerProductTranslation(11149, $product, $locale));
        $this->_em->flush();
        $this->_em->clear();

        // Fetch entity from database
        $product = $this->_em->find(EagerProduct::class, 11149);

        // Assert associated entity is loaded eagerly
        static::assertInstanceOf(EagerProduct::class, $product);
        static::assertInstanceOf(PersistentCollection::class, $product->translations);
        static::assertTrue($product->translations->isInitialized());
        static::assertCount(1, $product->translations);

        // Assert associated entity is indexed by given property
        $translation = $product->translations->get('fr_FR');
        static::assertInstanceOf(EagerProductTranslation::class, $translation);
        $reflection = new ReflectionClass($translation);
        static::assertFalse($reflection->isUninitializedLazyObject($translation));
    }
}
