<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH-9863')]
class GH9863Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Type::hasType(CustomIdObjectType::NAME)) {
            Type::overrideType(CustomIdObjectType::NAME, CustomIdObjectType::class);
        } else {
            Type::addType(CustomIdObjectType::NAME, CustomIdObjectType::class);
        }

        $this->createSchemaForModels(GH9863Feed::class, GH9863Item::class);
    }

    public function testLazyLoadingAnEntityWithAReadonlyObjectIdentifier(): void
    {
        $feed = new GH9863Feed(new CustomIdObject('feed-1'), 'News');
        $item = new GH9863Item($feed);

        $this->_em->persist($feed);
        $this->_em->persist($item);
        $this->_em->flush();
        $this->_em->clear();

        $item = $this->_em->find(GH9863Item::class, $item->id);

        self::assertTrue($this->isUninitializedObject($item->feed));
        self::assertSame('News', $item->feed->title);
        self::assertSame('feed-1', $item->feed->id->id);
    }

    public function testInitializingAReferenceWithAReadonlyObjectIdentifier(): void
    {
        $feed = new GH9863Feed(new CustomIdObject('feed-2'), 'Sports');

        $this->_em->persist($feed);
        $this->_em->flush();
        $this->_em->clear();

        $reference = $this->_em->getReference(GH9863Feed::class, new CustomIdObject('feed-2'));

        self::assertSame('Sports', $reference->title);
    }
}

#[Entity]
class GH9863Feed
{
    #[Id]
    #[Column(type: CustomIdObjectType::NAME, length: 255)]
    #[GeneratedValue(strategy: 'NONE')]
    public readonly CustomIdObject $id;

    #[Column(type: 'string', length: 255)]
    public string $title;

    public function __construct(CustomIdObject $id, string $title)
    {
        $this->id    = $id;
        $this->title = $title;
    }
}

#[Entity]
class GH9863Item
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public int|null $id = null;

    #[ManyToOne(targetEntity: GH9863Feed::class)]
    public GH9863Feed $feed;

    public function __construct(GH9863Feed $feed)
    {
        $this->feed = $feed;
    }
}
