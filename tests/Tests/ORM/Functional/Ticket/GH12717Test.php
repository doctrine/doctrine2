<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_map;

/** @see https://github.com/doctrine/orm/issues/12717 */
#[Group('GH12717')]
final class GH12717Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12717Entity::class);

        foreach (['ccc', 'a', 'bb'] as $name) {
            $entity       = new GH12717Entity();
            $entity->name = $name;
            $this->_em->persist($entity);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->_em)) {
            $this->_em->getConnection()->executeStatement('DELETE FROM GH12717Entity');
        }

        parent::tearDown();
    }

    public function testOrderByExpressionWithoutOutputWalkers(): void
    {
        $query = $this->_em->createQuery(
            'SELECT e FROM ' . GH12717Entity::class . ' e ORDER BY LENGTH(e.name) ASC',
        );

        $page = (new OffsetPaginator(true, false))->paginate($query, new Window(0, 2));

        self::assertSame(
            ['a', 'bb'],
            array_map(static fn (GH12717Entity $entity): string => $entity->name, $page->getItems()),
        );
    }
}

#[Entity]
class GH12717Entity
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $name;
}
