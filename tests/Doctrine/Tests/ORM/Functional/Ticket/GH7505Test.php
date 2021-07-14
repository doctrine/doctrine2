<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * @group GH7505
 */
final class GH7505Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH7505AbstractResponse::class,
            GH7505ArrayResponse::class,
            GH7505TextResponse::class,
        ]);
    }

    public function testSimpleArrayTypeHydratedCorrectly(): void
    {
        $arrayResponse = new GH7505ArrayResponse();
        $this->_em->persist($arrayResponse);

        $textResponse = new GH7505TextResponse();
        $this->_em->persist($textResponse);

        $this->_em->flush();
        $this->_em->clear();

        $repository = $this->_em->getRepository(GH7505AbstractResponse::class);

        $arrayResponse = $repository->find($arrayResponse->id);
        assert($arrayResponse instanceof GH7505ArrayResponse);
        self::assertSame([], $arrayResponse->value);

        $textResponse = $repository->find($textResponse->id);
        assert($textResponse instanceof GH7505TextResponse);
        self::assertNull($textResponse->value);
    }
}

/**
 * @Entity()
 * @Table(name="gh7505_responses")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *     "array" = GH7505ArrayResponse::class,
 *     "text"  = GH7505TextResponse::class,
 * })
 */
abstract class GH7505AbstractResponse
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;
}

/**
 * @Entity()
 */
class GH7505ArrayResponse extends GH7505AbstractResponse
{
    /**
     * @var mixed[]
     * @Column(name="value_array", type="simple_array")
     */
    public $value = [];
}

/**
 * @Entity()
 */
class GH7505TextResponse extends GH7505AbstractResponse
{
    /**
     * @Column(name="value_string", type="string")
     * @var string|null
     */
    public $value;
}
