<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

use function date;
use function strtotime;

/**
 * @Entity
 * @Cache("READ_ONLY")
 * @Table("cache_token")
 */
class Token
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     */
    public $token;

    /**
     * @var DateTime
     * @Column(type="date")
     */
    public $expiresAt;

    /**
     * @var Client|null
     * @OneToOne(targetEntity="Client")
     */
    public $client;

    /**
     * @psalm-var Collection<int, Login>
     * @OneToMany(targetEntity="Login", cascade={"persist", "remove"}, mappedBy="token")
     */
    public $logins;

    /**
     * @var Action
     * @ManyToOne(targetEntity="Action", cascade={"persist", "remove"}, inversedBy="tokens")
     * @JoinColumn(name="action_name", referencedColumnName="name")
     */
    public $action;

    /**
     * @ManyToOne(targetEntity="ComplexAction", cascade={"persist", "remove"}, inversedBy="tokens")
     * @JoinColumns({
     *   @JoinColumn(name="complex_action1_name", referencedColumnName="action1_name"),
     *   @JoinColumn(name="complex_action2_name", referencedColumnName="action2_name")
     * })
     * @var ComplexAction
     */
    public $complexAction;

    public function __construct(string $token, ?Client $client = null)
    {
        $this->logins    = new ArrayCollection();
        $this->token     = $token;
        $this->client    = $client;
        $this->expiresAt = new DateTime(date('Y-m-d H:i:s', strtotime('+7 day')));
    }

    public function addLogin(Login $login): void
    {
        $this->logins[] = $login;
        $login->token   = $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getAction(): Action
    {
        return $this->action;
    }

    public function getComplexAction(): ComplexAction
    {
        return $this->complexAction;
    }
}
