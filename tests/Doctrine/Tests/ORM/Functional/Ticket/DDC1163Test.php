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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function get_class;

/**
 * @group DDC-1163
 */
class DDC1163Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1163Product::class),
                $this->_em->getClassMetadata(DDC1163SpecialProduct::class),
                $this->_em->getClassMetadata(DDC1163ProxyHolder::class),
                $this->_em->getClassMetadata(DDC1163Tag::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $this->createSpecialProductAndProxyHolderReferencingIt();
        $this->_em->clear();

        $this->createProxyForSpecialProduct();

        $this->setPropertyAndAssignTagToSpecialProduct();

        // fails
        $this->_em->flush();
    }

    private function createSpecialProductAndProxyHolderReferencingIt(): void
    {
        $specialProduct = new DDC1163SpecialProduct();
        $this->_em->persist($specialProduct);

        $proxyHolder = new DDC1163ProxyHolder();
        $this->_em->persist($proxyHolder);

        $proxyHolder->setSpecialProduct($specialProduct);

        $this->_em->flush();

        $this->productId     = $specialProduct->getId();
        $this->proxyHolderId = $proxyHolder->getId();
    }

    /**
     * We want Doctrine to instantiate a lazy-load proxy for the previously created
     * 'SpecialProduct' and register it.
     *
     * When Doctrine loads the 'ProxyHolder', it will do just that because the 'ProxyHolder'
     * references the 'SpecialProduct'.
     */
    private function createProxyForSpecialProduct(): void
    {
        $proxyHolder = $this->_em->find(DDC1163ProxyHolder::class, $this->proxyHolderId);
        assert($proxyHolder instanceof DDC1163ProxyHolder);

        $this->assertInstanceOf(DDC1163SpecialProduct::class, $proxyHolder->getSpecialProduct());
    }

    private function setPropertyAndAssignTagToSpecialProduct(): void
    {
        $specialProduct = $this->_em->find(DDC1163SpecialProduct::class, $this->productId);
        assert($specialProduct instanceof DDC1163SpecialProduct);

        $this->assertInstanceOf(DDC1163SpecialProduct::class, $specialProduct);
        $this->assertInstanceOf(Proxy::class, $specialProduct);

        $specialProduct->setSubclassProperty('foobar');

        // this screams violation of law of demeter ;)
        $this->assertEquals(
            DDC1163SpecialProduct::class,
            $this->_em->getUnitOfWork()->getEntityPersister(get_class($specialProduct))->getClassMetadata()->name
        );

        $tag = new DDC1163Tag('Foo');
        $this->_em->persist($tag);
        $tag->setProduct($specialProduct);
    }
}

/**
 * @Entity
 */
class DDC1163ProxyHolder
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var DDC1163SpecialProduct
     * @OneToOne(targetEntity="DDC1163SpecialProduct")
     */
    private $specialProduct;

    public function getId(): int
    {
        return $this->id;
    }

    public function setSpecialProduct(DDC1163SpecialProduct $specialProduct): void
    {
        $this->specialProduct = $specialProduct;
    }

    public function getSpecialProduct(): DDC1163SpecialProduct
    {
        return $this->specialProduct;
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({"special" = "DDC1163SpecialProduct"})
 */
abstract class DDC1163Product
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class DDC1163SpecialProduct extends DDC1163Product
{
    /**
     * @var string
     * @Column(name="subclass_property", type="string", nullable=true)
     */
    private $subclassProperty;

    public function setSubclassProperty(string $value): void
    {
        $this->subclassProperty = $value;
    }
}

/**
 * @Entity
 */
class DDC1163Tag
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;
    /**
     * @var string
     * @Column(name="name", type="string")
     */
    private $name;
    /**
     * @var Product
     * @ManyToOne(targetEntity="DDC1163Product", inversedBy="tags")
     * @JoinColumns({
     *   @JoinColumn(name="product_id", referencedColumnName="id")
     * })
     */
    private $product;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function setProduct(DDC1163Product $product): void
    {
        $this->product = $product;
    }
}
