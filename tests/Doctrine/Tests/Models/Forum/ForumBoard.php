<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Forum;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * Represents a board in a forum.
 *
 * @Entity
 * @Table(name="forum_boards")
 */
class ForumBoard
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $position;
    /**
     * @var ForumCategory
     * @ManyToOne(targetEntity="ForumCategory", inversedBy="boards")
     * @JoinColumn(name="category_id", referencedColumnName="id")
     */
    public $category;
}
