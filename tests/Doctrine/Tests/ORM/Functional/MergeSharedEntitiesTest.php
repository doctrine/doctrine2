<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Tests\OrmFunctionalTestCase;

use function serialize;
use function unserialize;

class MergeSharedEntitiesTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(MSEFile::class),
                    $this->_em->getClassMetadata(MSEPicture::class),
                ]
            );
        } catch (ToolsException $ignored) {
        }
    }

    public function testMergeSharedNewEntities(): void
    {
        $file    = new MSEFile();
        $picture = new MSEPicture();

        $picture->file      = $file;
        $picture->otherFile = $file;

        $picture = $this->_em->merge($picture);

        $this->assertEquals($picture->file, $picture->otherFile, 'Identical entities must remain identical');
    }

    public function testMergeSharedManagedEntities(): void
    {
        $file    = new MSEFile();
        $picture = new MSEPicture();

        $picture->file      = $file;
        $picture->otherFile = $file;

        $this->_em->persist($file);
        $this->_em->persist($picture);
        $this->_em->flush();
        $this->_em->clear();

        $picture = $this->_em->merge($picture);

        $this->assertEquals($picture->file, $picture->otherFile, 'Identical entities must remain identical');
    }

    public function testMergeSharedDetachedSerializedEntities(): void
    {
        $file    = new MSEFile();
        $picture = new MSEPicture();

        $picture->file      = $file;
        $picture->otherFile = $file;

        $serializedPicture = serialize($picture);

        $this->_em->persist($file);
        $this->_em->persist($picture);
        $this->_em->flush();
        $this->_em->clear();

        $picture = $this->_em->merge(unserialize($serializedPicture));

        $this->assertEquals($picture->file, $picture->otherFile, 'Identical entities must remain identical');
    }

    /**
     * @group DDC-2704
     */
    public function testMergeInheritedTransientPrivateProperties(): void
    {
        $admin1 = new MSEAdmin();
        $admin2 = new MSEAdmin();

        $admin1->id = 123;
        $admin2->id = 123;

        $this->_em->persist($admin1);

        $admin2->setSession('zeh current session data');

        $this->assertSame($admin1, $this->_em->merge($admin2));
        $this->assertSame('zeh current session data', $admin1->getSession());
    }
}

/** @Entity */
class MSEPicture
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     */
    public $id;

    /**
     * @var MSEFile
     * @ManyToOne(targetEntity="MSEFile", cascade={"merge"})
     */
    public $file;

    /**
     * @var MSEFile
     * @ManyToOne(targetEntity="MSEFile", cascade={"merge"})
     */
    public $otherFile;
}

/** @Entity */
class MSEFile
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}

/** @MappedSuperclass */
abstract class MSEUser
{
    /** @var string */
    private $session; // intentionally transient property

    public function getSession(): string
    {
        return $this->session;
    }

    public function setSession(string $session): void
    {
        $this->session = $session;
    }
}

/** @Entity */
class MSEAdmin extends MSEUser
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="NONE")
     */
    public $id;
}
