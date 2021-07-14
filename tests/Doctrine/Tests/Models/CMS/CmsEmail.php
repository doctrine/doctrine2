<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CMS;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * CmsEmail
 *
 * @Entity
 * @Table(name="cms_emails")
 */
class CmsEmail
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(length=250)
     */
    public $email;

    /**
     * @var CmsUser
     * @OneToOne(targetEntity="CmsUser", mappedBy="email")
     */
    public $user;

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getUser(): CmsUser
    {
        return $this->user;
    }

    public function setUser(CmsUser $user): void
    {
        $this->user = $user;
    }
}
