<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-2759
 */
class DDC2759Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2759Qualification::class),
                    $this->_em->getClassMetadata(DDC2759Category::class),
                    $this->_em->getClassMetadata(DDC2759QualificationMetadata::class),
                    $this->_em->getClassMetadata(DDC2759MetadataCategory::class),
                ]
            );
        } catch (Exception $e) {
            return;
        }

        $qualification         = new DDC2759Qualification();
        $qualificationMetadata = new DDC2759QualificationMetadata($qualification);

        $category1 = new DDC2759Category();
        $category2 = new DDC2759Category();

        $metadataCategory1 = new DDC2759MetadataCategory($qualificationMetadata, $category1);
        $metadataCategory2 = new DDC2759MetadataCategory($qualificationMetadata, $category2);

        $this->_em->persist($qualification);
        $this->_em->persist($qualificationMetadata);

        $this->_em->persist($category1);
        $this->_em->persist($category2);

        $this->_em->persist($metadataCategory1);
        $this->_em->persist($metadataCategory2);

        $this->_em->flush();
        $this->_em->clear();
    }

    public function testCorrectNumberOfAssociationsIsReturned(): void
    {
        $repository = $this->_em->getRepository(DDC2759Qualification::class);

        $builder = $repository->createQueryBuilder('q')
            ->select('q, qm, qmc')
            ->innerJoin('q.metadata', 'qm')
            ->innerJoin('qm.metadataCategories', 'qmc');

        $result = $builder->getQuery()
            ->getArrayResult();

        $this->assertCount(2, $result[0]['metadata']['metadataCategories']);
    }
}

/** @Entity  @Table(name="ddc_2759_qualification") */
class DDC2759Qualification
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2759QualificationMetadata
     * @OneToOne(targetEntity="DDC2759QualificationMetadata", mappedBy="content")
     */
    public $metadata;
}

/** @Entity  @Table(name="ddc_2759_category") */
class DDC2759Category
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC2759MetadataCategory>
     * @OneToMany(targetEntity="DDC2759MetadataCategory", mappedBy="category")
     */
    public $metadataCategories;
}

/** @Entity  @Table(name="ddc_2759_qualification_metadata") */
class DDC2759QualificationMetadata
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2759Qualification
     * @OneToOne(targetEntity="DDC2759Qualification", inversedBy="metadata")
     */
    public $content;

    /**
     * @psalm-var Collection<int, DDC2759MetadataCategory>
     * @OneToMany(targetEntity="DDC2759MetadataCategory", mappedBy="metadata")
     */
    protected $metadataCategories;

    public function __construct(DDC2759Qualification $content)
    {
        $this->content = $content;
    }
}

/** @Entity  @Table(name="ddc_2759_metadata_category") */
class DDC2759MetadataCategory
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2759QualificationMetadata
     * @ManyToOne(targetEntity="DDC2759QualificationMetadata", inversedBy="metadataCategories")
     */
    public $metadata;

    /**
     * @var DDC2759Category
     * @ManyToOne(targetEntity="DDC2759Category", inversedBy="metadataCategories")
     */
    public $category;

    public function __construct(DDC2759QualificationMetadata $metadata, DDC2759Category $category)
    {
        $this->metadata = $metadata;
        $this->category = $category;
    }
}
