<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1392
 */
class DDC1392Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1392File::class),
                    $this->_em->getClassMetadata(DDC1392Picture::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testFailingCase(): void
    {
        $file = new DDC1392File();

        $picture = new DDC1392Picture();
        $picture->setFile($file);

        $em = $this->_em;
        $em->persist($picture);
        $em->flush();
        $em->clear();

        $fileId    = $file->getFileId();
        $pictureId = $picture->getPictureId();

        $this->assertTrue($fileId > 0);

        $picture = $em->find(DDC1392Picture::class, $pictureId);
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $em->getUnitOfWork()->getEntityState($picture->getFile()), 'Lazy Proxy should be marked MANAGED.');

        $file = $picture->getFile();

        // With this activated there will be no problem
        //$file->__load();

        $picture->setFile(null);

        $em->clear();

        $em->merge($file);

        $em->flush();

        $q      = $this->_em->createQuery('SELECT COUNT(e) FROM ' . __NAMESPACE__ . '\DDC1392File e');
        $result = $q->getSingleScalarResult();

        self::assertEquals(1, $result);
    }
}

/**
 * @Entity
 */
class DDC1392Picture
{
    /**
     * @var int
     * @Column(name="picture_id", type="integer")
     * @Id
     * @GeneratedValue
     */
    private $pictureId;

    /**
     * @var DDC1392File
     * @ManyToOne(targetEntity="DDC1392File", cascade={"persist", "remove"})
     * @JoinColumn(name="file_id", referencedColumnName="file_id")
     */
    private $file;

    public function getPictureId(): int
    {
        return $this->pictureId;
    }

    public function setFile(?DDC1392File $value = null): void
    {
        $this->file = $value;
    }

    public function getFile(): ?DDC1392File
    {
        return $this->file;
    }
}

/**
 * @Entity
 */
class DDC1392File
{
    /**
     * @var int
     * @Column(name="file_id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $fileId;

    public function getFileId(): int
    {
        return $this->fileId;
    }
}
