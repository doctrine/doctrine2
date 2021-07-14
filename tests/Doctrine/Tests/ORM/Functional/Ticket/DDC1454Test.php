<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function getrandmax;
use function random_int;

/**
 * @group DDC-1454
 */
class DDC1454Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1454File::class),
                    $this->_em->getClassMetadata(DDC1454Picture::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testFailingCase(): void
    {
        $pic = new DDC1454Picture();

        self::assertSame(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($pic));
    }
}

/**
 * @Entity
 */
class DDC1454Picture extends DDC1454File
{
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"file" = "DDC1454File", "picture" = "DDC1454Picture"})
 */
class DDC1454File
{
    /**
     * @var int
     * @Column(name="file_id", type="integer")
     * @Id
     */
    public $fileId;

    public function __construct()
    {
        $this->fileId = random_int(0, getrandmax());
    }

    public function getFileId(): int
    {
        return $this->fileId;
    }
}
