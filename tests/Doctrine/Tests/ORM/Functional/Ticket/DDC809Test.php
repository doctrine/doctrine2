<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

class DDC809Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC809Variant::class),
                $this->_em->getClassMetadata(DDC809SpecificationValue::class),
            ]
        );

        $conn = $this->_em->getConnection();
        $conn->insert('specification_value_test', ['specification_value_id' => 94589]);
        $conn->insert('specification_value_test', ['specification_value_id' => 94593]);
        $conn->insert('specification_value_test', ['specification_value_id' => 94606]);
        $conn->insert('specification_value_test', ['specification_value_id' => 94607]);
        $conn->insert('specification_value_test', ['specification_value_id' => 94609]);
        $conn->insert('specification_value_test', ['specification_value_id' => 94711]);

        $conn->insert('variant_test', ['variant_id' => 545208]);
        $conn->insert('variant_test', ['variant_id' => 545209]);

        $conn->insert('var_spec_value_test', ['variant_id' => 545208, 'specification_value_id' => 94606]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545208, 'specification_value_id' => 94607]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545208, 'specification_value_id' => 94609]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545208, 'specification_value_id' => 94711]);

        $conn->insert('var_spec_value_test', ['variant_id' => 545209, 'specification_value_id' => 94589]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545209, 'specification_value_id' => 94593]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545209, 'specification_value_id' => 94606]);
        $conn->insert('var_spec_value_test', ['variant_id' => 545209, 'specification_value_id' => 94607]);
    }

    /**
     * @group DDC-809
     */
    public function testIssue(): void
    {
        $result = $this->_em->createQueryBuilder()
                        ->select('Variant, SpecificationValue')
                        ->from(DDC809Variant::class, 'Variant')
                        ->leftJoin('Variant.specificationValues', 'SpecificationValue')
                        ->getQuery()
                        ->getResult();

        $this->assertEquals(4, count($result[0]->getSpecificationValues()), 'Works in test-setup.');
        $this->assertEquals(4, count($result[1]->getSpecificationValues()), 'Only returns 2 in the case of the hydration bug.');
    }
}

/**
 * @Table(name="variant_test")
 * @Entity
 */
class DDC809Variant
{
    /**
     * @var int
     * @Column(name="variant_id", type="integer")
     * @Id
     */
    protected $variantId;

    /**
     * @psalm-var Collection<int, DDC809SpecificationValue>
     * @ManyToMany(targetEntity="DDC809SpecificationValue", inversedBy="Variants")
     * @JoinTable(name="var_spec_value_test",
     *   joinColumns={
     *     @JoinColumn(name="variant_id", referencedColumnName="variant_id")
     *   },
     *   inverseJoinColumns={
     *     @JoinColumn(name="specification_value_id", referencedColumnName="specification_value_id")
     *   }
     * )
     */
    protected $specificationValues;

    /**
     * @psalm-return Collection<int, DDC809SpecificationValue>
     */
    public function getSpecificationValues(): Collection
    {
        return $this->specificationValues;
    }
}

/**
 * @Table(name="specification_value_test")
 * @Entity
 */
class DDC809SpecificationValue
{
    /**
     * @var int
     * @Column(name="specification_value_id", type="integer")
     * @Id
     */
    protected $specificationValueId;

    /**
     * @psalm-var Collection<int,DDC809Variant>
     * @ManyToMany(targetEntity="DDC809Variant", mappedBy="SpecificationValues")
     */
    protected $variants;
}
