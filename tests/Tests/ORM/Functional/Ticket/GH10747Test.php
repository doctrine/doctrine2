<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function str_replace;

/**
 * Functional tests for asserting that orphaned children in a OneToMany relationship get removed with a custom identifier
 */
#[Group('GH10747')]
final class GH10747Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH10747CustomIdObjectHashType::class, GH10747CustomIdObjectHashType::class);

        $this->setUpEntitySchema([GH10747Article::class, GH10747Credit::class]);
    }

    public function testOrphanedOneToManyDeletesCollection(): void
    {
        $object = new GH10747Article(
            new CustomIdObject('article'),
        );

        $creditOne = new GH10747Credit(
            $object,
            'credit1',
        );

        $creditTwo = new GH10747Credit(
            $object,
            'credit2',
        );

        $object->setCredits(new ArrayCollection([$creditOne, $creditTwo]));

        $this->_em->persist($object);
        $this->_em->persist($creditOne);
        $this->_em->persist($creditTwo);
        $this->_em->flush();

        $id = $object->id;

        $object2 = $this->_em->find(GH10747Article::class, $id);

        $creditThree = new GH10747Credit(
            $object2,
            'credit3',
        );

        $object2->setCredits(new ArrayCollection([$creditThree]));

        $this->_em->persist($object2);
        $this->_em->persist($creditThree);
        $this->_em->flush();

        $currentDatabaseCredits = $this->_em->createQueryBuilder()
            ->select('c.id')
            ->from(GH10747Credit::class, 'c')
            ->getQuery()
            ->execute();

        self::assertCount(1, $currentDatabaseCredits);
    }
}

#[Entity]
#[Table]
class GH10747Article
{
    #[Id]
    #[Column(type: GH10747CustomIdObjectHashType::class, length: 24)] // strlen(PHP_INT_MAX . '_test')
    public CustomIdObject $id;

    /** @var Collection<int, GH10747Credit> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: GH10747Credit::class, orphanRemoval: true)]
    public Collection $credits;

    public function __construct(CustomIdObject $id)
    {
        $this->id      = $id;
        $this->credits = new ArrayCollection();
    }

    public function setCredits(Collection $credits): void
    {
        $this->credits = $credits;
    }

    /** @return Collection<int, GH10747Credit> */
    public function getCredits(): Collection
    {
        return $this->credits;
    }
}

#[Entity]
#[Table]
class GH10747Credit
{
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    #[Id]
    public int|null $id = null;

    #[ORM\ManyToOne(inversedBy: 'credits', targetEntity: GH10747Article::class)]
    public GH10747Article $article;

    public function __construct(GH10747Article $article, public string $name)
    {
        $this->article = $article;
    }
}

class GH10747CustomIdObjectHashType extends DBALType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        return $value->id . '_test';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): CustomIdObject
    {
        return new CustomIdObject(str_replace('_test', '', $value));
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return self::class;
    }
}
