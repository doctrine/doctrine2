<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CMS\CmsEmail;

/**
 * @group DDC-1666
 */
class DDC1666Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function testGivenOrphanRemovalOneToOne_WhenReplacing_ThenNoUniqueConstraintError()
    {
        $user = new CmsUser();
        $user->name = "Benjamin";
        $user->username = "beberlei";
        $user->status = "something";
        $user->setEmail($email = new CmsEmail());
        $email->setEmail("kontakt@beberlei.de");

        $this->em->persist($user);
        $this->em->flush();

        self::assertTrue($this->em->contains($email));

        $user->setEmail($newEmail = new CmsEmail());
        $newEmail->setEmail("benjamin.eberlei@googlemail.com");

        $this->em->flush();

        self::assertFalse($this->em->contains($email));
    }
}
