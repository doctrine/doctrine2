<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_action")
 */
class Action
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    public $name;

    /**
     * @psalm-var Collection<int, Token>
     * @OneToMany(targetEntity="Token", cascade={"persist", "remove"}, mappedBy="action")
     */
    public $tokens;

    public function __construct($name)
    {
        $this->name   = $name;
        $this->tokens = new ArrayCollection();
    }

    public function addToken(Token $token): void
    {
        $this->tokens[] = $token;
        $token->action  = $this;
    }
}
