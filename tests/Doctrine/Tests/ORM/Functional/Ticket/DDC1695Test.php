<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1695
 */
class DDC1695Test extends OrmFunctionalTestCase
{
    public function testIssue(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'sqlite') {
            $this->markTestSkipped('Only with sqlite');
        }

        $dql = 'SELECT n.smallText, n.publishDate FROM ' . __NAMESPACE__ . '\\DDC1695News n';
        $sql = $this->_em->createQuery($dql)->getSQL();

        $this->assertEquals(
            'SELECT d0_."SmallText" AS SmallText_0, d0_."PublishDate" AS PublishDate_1 FROM "DDC1695News" d0_',
            $sql
        );
    }
}

/**
 * @Table(name="`DDC1695News`")
 * @Entity
 */
class DDC1695News
{
    /**
     * @var int
     * @Column(name="`IdNews`", type="integer", nullable=false)
     * @Id
     * @GeneratedValue
     */
    private $idNews;

    /**
     * @var int
     * @Column(name="`IdUser`", type="bigint", nullable=false)
     */
    private $idUser;

    /**
     * @var int
     * @Column(name="`IdLanguage`", type="integer", nullable=false)
     */
    private $idLanguage;

    /**
     * @var int
     * @Column(name="`IdCondition`", type="integer", nullable=true)
     */
    private $idCondition;

    /**
     * @var int
     * @Column(name="`IdHealthProvider`", type="integer", nullable=true)
     */
    private $idHealthProvider;

    /**
     * @var int
     * @Column(name="`IdSpeciality`", type="integer", nullable=true)
     */
    private $idSpeciality;

    /**
     * @var int
     * @Column(name="`IdMedicineType`", type="integer", nullable=true)
     */
    private $idMedicineType;

    /**
     * @var int
     * @Column(name="`IdTreatment`", type="integer", nullable=true)
     */
    private $idTreatment;

    /**
     * @var string
     * @Column(name="`Title`", type="string", nullable=true)
     */
    private $title;

    /**
     * @var string
     * @Column(name="`SmallText`", type="string", nullable=true)
     */
    private $smallText;

    /**
     * @var string
     * @Column(name="`LongText`", type="string", nullable=true)
     */
    private $longText;

    /**
     * @var DateTimeZone
     * @Column(name="`PublishDate`", type="datetimetz", nullable=true)
     */
    private $publishDate;

    /**
     * @var array
     * @Column(name="`IdxNews`", type="json_array", nullable=true)
     */
    private $idxNews;

    /**
     * @var bool
     * @Column(name="`Highlight`", type="boolean", nullable=false)
     */
    private $highlight;

    /**
     * @var int
     * @Column(name="`Order`", type="integer", nullable=false)
     */
    private $order;

    /**
     * @var bool
     * @Column(name="`Deleted`", type="boolean", nullable=false)
     */
    private $deleted;

    /**
     * @var bool
     * @Column(name="`Active`", type="boolean", nullable=false)
     */
    private $active;

    /**
     * @var bool
     * @Column(name="`UpdateToHighlighted`", type="boolean", nullable=true)
     */
    private $updateToHighlighted;
}
