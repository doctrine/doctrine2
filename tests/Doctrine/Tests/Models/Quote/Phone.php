<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Quote;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="`quote-phone`")
 */
class Phone
{
    /**
     * @var string
     * @Id
     * @Column(name="`phone-number`")
     */
    public $number;

    /**
     * @var User
     * @ManyToOne(targetEntity="User", inversedBy="phones")
     * @JoinColumn(name="`user-id`", referencedColumnName="`user-id`")
     */
    public $user;
}
