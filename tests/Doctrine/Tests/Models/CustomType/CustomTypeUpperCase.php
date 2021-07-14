<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="customtype_uppercases")
 */
class CustomTypeUpperCase
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="upper_case_string")
     */
    public $lowerCaseString;

    /**
     * @var string
     * @Column(type="upper_case_string", name="named_lower_case_string", nullable = true)
     */
    public $namedLowerCaseString;
}
