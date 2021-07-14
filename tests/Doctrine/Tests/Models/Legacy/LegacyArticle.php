<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Legacy;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="legacy_articles")
 */
class LegacyArticle
{
    /**
     * @var int
     * @Id
     * @Column(name="iArticleId", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(name="sTopic", type="string", length=255)
     */
    public $topic;

    /**
     * @var string
     * @Column(name="sText", type="text")
     */
    public $text;

    /**
     * @var LegacyUser
     * @ManyToOne(targetEntity="LegacyUser", inversedBy="articles")
     * @JoinColumn(name="iUserId", referencedColumnName="iUserId")
     */
    public $user;

    public function setAuthor(LegacyUser $author): void
    {
        $this->user = $author;
    }
}
