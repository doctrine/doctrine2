<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC117;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * @Entity
 */
class DDC117ApproveChanges
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var DDC117ArticleDetails
     * @ManyToOne(targetEntity="DDC117ArticleDetails")
     * @JoinColumn(name="details_id", referencedColumnName="article_id")
     */
    private $articleDetails;

    /**
     * @var DDC117Reference
     * @ManyToOne(targetEntity="DDC117Reference")
     * @JoinColumns({
     *  @JoinColumn(name="source_id", referencedColumnName="source_id"),
     *  @JoinColumn(name="target_id", referencedColumnName="target_id")
     * })
     */
    private $reference;

    /**
     * @var DDC117Translation
     * @ManyToOne(targetEntity="DDC117Translation")
     * @JoinColumns({
     *  @JoinColumn(name="trans_article_id", referencedColumnName="article_id"),
     *  @JoinColumn(name="trans_language", referencedColumnName="language")
     * })
     */
    private $translation;

    public function __construct(
        DDC117ArticleDetails $details,
        DDC117Reference $reference,
        DDC117Translation $translation
    ) {
        $this->articleDetails = $details;
        $this->reference      = $reference;
        $this->translation    = $translation;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getArticleDetails(): DDC117ArticleDetails
    {
        return $this->articleDetails;
    }

    public function getReference(): DDC117Reference
    {
        return $this->reference;
    }

    public function getTranslation(): DDC117Translation
    {
        return $this->translation;
    }
}
