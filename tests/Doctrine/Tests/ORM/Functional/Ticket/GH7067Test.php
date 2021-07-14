<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

final class GH7067Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->enableSecondLevelCache();
        parent::setUp();

        $this->setUpEntitySchema([GH7067Entity::class]);
    }

    /**
     * @group GH-7067
     */
    public function testSLCWithVersion(): void
    {
        $entity             = new GH7067Entity();
        $entity->lastUpdate = new DateTime();

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $notCached = $this->_em->find(GH7067Entity::class, $entity->id);
        assert($notCached instanceof GH7067Entity);

        self::assertNotNull($notCached->version, 'Version already cached by persister above, it must be not null');

        $notCached->lastUpdate = new DateTime('+1 seconds');

        $this->_em->flush();
        $this->_em->clear();
    }
}

/**
 * @Entity()
 * @Cache(usage="NONSTRICT_READ_WRITE")
 */
class GH7067Entity
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Column(type="datetime")
     * @var DateTime
     */
    public $lastUpdate;

    /**
     * @Column(type="datetime")
     * @Version
     * @var DateTime
     */
    public $version;
}
