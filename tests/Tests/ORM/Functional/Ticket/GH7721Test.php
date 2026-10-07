<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Replacing the target object of a one-to-one association from the inverse
 * side while the old target is removed explicitly must be possible in a
 * single flush() when the owning side's join column carries a unique
 * constraint (#7721); for a single one-to-one join column that uniqueness
 * is the mapping default.
 */
class GH7721Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GH7721Husband::$events = [];

        $this->createSchemaForModels(
            GH7721Wife::class,
            GH7721Husband::class,
        );
    }

    #[Group('gh7721-repro')]
    public function testReplaceOneToOneTargetFromInverseSideWithUniqueJoinColumnInSingleFlush(): void
    {
        $wife       = new GH7721Wife();
        $oldHusband = new GH7721Husband();
        $wife->setHusband($oldHusband);

        $this->_em->persist($wife);
        $this->_em->flush();

        $oldHusbandId = $oldHusband->id;

        $newHusband = new GH7721Husband();

        $this->_em->remove($oldHusband);
        $wife->setHusband($newHusband);

        // The new husband row references the same unique wife column value
        // as the row of the removed husband: the removal has to be executed
        // before the insertion for the flush to satisfy the constraint.
        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newHusband->id);
        self::assertNull($this->_em->find(GH7721Husband::class, $oldHusbandId));
        self::assertSame($newHusband->id, $this->_em->find(GH7721Wife::class, $wife->id)->husband->id);
    }

    #[Group('gh7721-no-collision')]
    public function testReplacementResolvingToDifferentReferencedEntityKeepsBaselineOrder(): void
    {
        $firstWife  = new GH7721Wife();
        $oldHusband = new GH7721Husband();
        $firstWife->setHusband($oldHusband);

        $secondWife = new GH7721Wife();

        $this->_em->persist($firstWife);
        $this->_em->persist($secondWife);
        $this->_em->flush();

        $oldHusbandId          = $oldHusband->id;
        GH7721Husband::$events = [];

        $newHusband = new GH7721Husband();

        $this->_em->remove($oldHusband);
        $secondWife->setHusband($newHusband);

        // The new husband references a different wife than the removed one:
        // no unique collision, so the flush keeps the baseline commit order
        // and still ends with the replacement in place of a distinct target.
        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newHusband->id);
        self::assertNull($this->_em->find(GH7721Husband::class, $oldHusbandId));
        self::assertSame(['insert', 'remove'], GH7721Husband::$events);
    }
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class GH7721Husband
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\OneToOne(targetEntity: GH7721Wife::class, inversedBy: 'husband')]
    public GH7721Wife|null $wife = null;

    /** @var list<string> */
    public static array $events = [];

    #[ORM\PostRemove]
    public function logRemove(): void
    {
        self::$events[] = 'remove';
    }

    #[ORM\PostPersist]
    public function logInsert(): void
    {
        self::$events[] = 'insert';
    }
}

#[ORM\Entity]
class GH7721Wife
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\OneToOne(targetEntity: GH7721Husband::class, mappedBy: 'wife', cascade: ['persist'])]
    public GH7721Husband|null $husband = null;

    public function setHusband(GH7721Husband $husband): void
    {
        $this->husband = $husband;
        $husband->wife = $this;
    }
}
