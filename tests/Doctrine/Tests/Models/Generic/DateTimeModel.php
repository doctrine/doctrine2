<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Generic;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="date_time_model")
 */
class DateTimeModel
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DateTime|null
     * @Column(name="col_datetime", type="datetime", nullable=true)
     */
    public $datetime;

    /**
     * @var DateTime|null
     * @Column(name="col_date", type="date", nullable=true)
     */
    public $date;

    /**
     * @var DateTime|null
     * @Column(name="col_time", type="time", nullable=true)
     */
    public $time;
}
