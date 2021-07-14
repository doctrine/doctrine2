<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function assert;

/**
 * @group DDC-1509
 */
class DDC1509Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1509AbstractFile::class),
                    $this->_em->getClassMetadata(DDC1509File::class),
                    $this->_em->getClassMetadata(DDC1509Picture::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testFailingCase(): void
    {
        $file      = new DDC1509File();
        $thumbnail = new DDC1509File();

        $picture = new DDC1509Picture();
        $picture->setFile($file);
        $picture->setThumbnail($thumbnail);

        $em = $this->_em;
        assert($em instanceof EntityManager);
        $em->persist($picture);
        $em->flush();
        $em->clear();

        $id = $picture->getPictureId();

        $pic = $em->merge($picture);
        assert($pic instanceof DDC1509Picture);

        $this->assertNotNull($pic->getThumbnail());
        $this->assertNotNull($pic->getFile());
    }
}

/**
 * @Entity
 */
class DDC1509Picture
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var DDC1509AbstractFile
     * @ManyToOne(targetEntity="DDC1509AbstractFile", cascade={"persist", "remove"})
     */
    private $thumbnail;

    /**
     * @var DDC1509AbstractFile|null
     * @ManyToOne(targetEntity="DDC1509AbstractFile", cascade={"persist", "remove"})
     */
    private $file;

    public function getPictureId(): int
    {
        return $this->id;
    }

    public function setFile(?DDC1509AbstractFile $value = null): void
    {
        $this->file = $value;
    }

    public function getFile(): ?DDC1509AbstractFile
    {
        return $this->file;
    }

    public function getThumbnail(): DDC1509AbstractFile
    {
        return $this->thumbnail;
    }

    public function setThumbnail(DDC1509AbstractFile $thumbnail): void
    {
        $this->thumbnail = $thumbnail;
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"abstractFile" = "DDC1509AbstractFile", "file" = "DDC1509File"})
 */
class DDC1509AbstractFile
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    public function getFileId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class DDC1509File extends DDC1509AbstractFile
{
}
