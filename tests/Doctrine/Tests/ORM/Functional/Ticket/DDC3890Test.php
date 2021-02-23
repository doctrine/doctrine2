<?php


namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;


/**
 * @group DDC-3890
 */
class DDC3890Test extends \Doctrine\Tests\OrmFunctionalTestCase {

    public function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function testIssue()
    {
        $user = new CmsUser();
        $user->name = "Benjamin";
        $user->username = "beberlei";
        $user->status = "active";
        $this->_em->persist($user);

        for ($i = 0; $i < 3; $i++) {
            $group = new CmsGroup();
            $group->name = "group" . $i;
            $user->groups[] = $group;
            $this->_em->persist($group);
        }
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $user->id);

        $criteria = Criteria::create()->where(Criteria::expr()->in('name', ['group1', 'group2']));
        $groups = $user->getGroups()->matching($criteria);

        $this->assertCount(2, $groups);   
    }
}