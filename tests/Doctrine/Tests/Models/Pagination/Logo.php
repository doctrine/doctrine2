<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Pagination;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * Logo
 *
 * @Author Bill Schaller
 * @Entity
 * @Table(name="pagination_logo")
 */
class Logo
{
    /**
     * @var int
     * @Column(type="integer") @Id
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $image;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $imageHeight;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $imageWidth;

    /**
     * @var Company
     * @OneToOne(targetEntity="Company", inversedBy="logo", cascade={"persist"})
     * @JoinColumn(name="company_id")
     */
    public $company;
}
