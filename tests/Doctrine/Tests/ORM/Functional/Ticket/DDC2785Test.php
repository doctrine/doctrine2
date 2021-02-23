<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\CMS\CmsPhonenumber;

/**
 * @group DDC-2785
 */
class DDC2785Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    private $hashes = array();

    public function testIssue()
    {
        $counter = 0;
        do {
            $object = $this->createObject($counter);
            $counter++;
            if ($counter > 1000) {
                //mark as skipped ? (I never hit this on PHP 5.3 at least)
                break;
            }
        } while ($object === false);

        $this->assertEquals(\Doctrine\ORM\UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($object));
    }

    private function createObject($counter)
    {
        $phone = new CmsPhonenumber();
        $phone->phonenumber = 'pn-' . $counter;
        $hash = spl_object_hash($phone);

        if (!array_key_exists($hash, $this->hashes)) {
            $x = $this->_em->getReference(get_class($phone), $phone->phonenumber);
            $this->_em->persist($phone);
            $this->hashes[$hash] = true;
            $this->_em->flush();
            $this->_em->remove($phone);
            $this->_em->flush();
            return false;
        }

        return $phone;
    }
}