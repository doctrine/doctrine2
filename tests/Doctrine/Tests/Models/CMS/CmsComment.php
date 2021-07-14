<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CMS;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="cms_comments")
 */
class CmsComment
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $topic;

    /**
     * @var string
     * @Column(type="string")
     */
    public $text;

    /**
     * @var CmsArticle
     * @ManyToOne(targetEntity="CmsArticle", inversedBy="comments")
     * @JoinColumn(name="article_id", referencedColumnName="id")
     */
    public $article;

    public function setArticle(CmsArticle $article): void
    {
        $this->article = $article;
    }

    public function __toString(): string
    {
        return self::class . '[id=' . $this->id . ']';
    }
}
