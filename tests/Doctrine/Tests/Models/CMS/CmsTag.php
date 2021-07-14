<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CMS;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * Description of CmsTag
 *
 * @Entity
 * @Table(name="cms_tags")
 */
class CmsTag
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(length=50, name="tag_name", nullable=true)
     */
    public $name;

    /**
     * @psalm-var Collection<int, CmsUser>
     * @ManyToMany(targetEntity="CmsUser", mappedBy="tags")
     */
    public $users;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addUser(CmsUser $user): void
    {
        $this->users[] = $user;
    }

    /**
     * @psalm-return Collection<int, CmsUser>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
