<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CMS;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * Description of CmsEmployee
 *
 * @Entity
 * @Table(name="cms_employees")
 */
class CmsEmployee
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column
     */
    private $name;

    /**
     * @var CmsEmployee
     * @OneToOne(targetEntity="CmsEmployee")
     * @JoinColumn(name="spouse_id", referencedColumnName="id")
     */
    private $spouse;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSpouse(): ?CmsEmployee
    {
        return $this->spouse;
    }
}
