<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC1590;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity
 * @MappedSuperClass
 */
abstract class DDC1590Entity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    protected $createdAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function setCreatedAt(DateTime $createdAt): DDC1590User
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
}
