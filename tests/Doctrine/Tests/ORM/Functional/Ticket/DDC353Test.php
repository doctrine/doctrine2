<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC353Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC353File::class),
                    $this->_em->getClassMetadata(DDC353Picture::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testWorkingCase(): void
    {
        $file = new DDC353File();

        $picture = new DDC353Picture();
        $picture->setFile($file);

        $em = $this->_em;
        $em->persist($picture);
        $em->flush();
        $em->clear();

        $fileId = $file->getFileId();
        $this->assertTrue($fileId > 0);

        $file = $em->getReference(DDC353File::class, $fileId);
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $em->getUnitOfWork()->getEntityState($file), 'Reference Proxy should be marked MANAGED.');

        $picture = $em->find(DDC353Picture::class, $picture->getPictureId());
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $em->getUnitOfWork()->getEntityState($picture->getFile()), 'Lazy Proxy should be marked MANAGED.');

        $em->remove($picture);
        $em->flush();
    }

    public function testFailingCase(): void
    {
        $file = new DDC353File();

        $picture = new DDC353Picture();
        $picture->setFile($file);

        $em = $this->_em;
        $em->persist($picture);
        $em->flush();
        $em->clear();

        $fileId    = $file->getFileId();
        $pictureId = $picture->getPictureId();

        $this->assertTrue($fileId > 0);

        $picture = $em->find(DDC353Picture::class, $pictureId);
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $em->getUnitOfWork()->getEntityState($picture->getFile()), 'Lazy Proxy should be marked MANAGED.');

        $em->remove($picture);
        $em->flush();
    }
}

/**
 * @Entity
 */
class DDC353Picture
{
    /**
     * @var int
     * @Column(name="picture_id", type="integer")
     * @Id
     * @GeneratedValue
     */
    private $pictureId;

    /**
     * @var DDC353File
     * @ManyToOne(targetEntity="DDC353File", cascade={"persist", "remove"})
     * @JoinColumns({
     *   @JoinColumn(name="file_id", referencedColumnName="file_id")
     * })
     */
    private $file;

    public function getPictureId(): int
    {
        return $this->pictureId;
    }

    public function setFile(DDC353File $value): void
    {
        $this->file = $value;
    }

    public function getFile(): DDC353File
    {
        return $this->file;
    }
}

/**
 * @Entity
 */
class DDC353File
{
    /**
     * @var int
     * @Column(name="file_id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $fileId;

    /**
     * Get fileId
     */
    public function getFileId(): int
    {
        return $this->fileId;
    }
}
