<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC117;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * @Entity
 */
class DDC117Article
{
    /**
     * @var int
     * @Id @Column(type="integer", name="article_id") @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column
     */
    private $title;

    /**
     * @psalm-var Collection<int, DDC117Reference>
     * @OneToMany(targetEntity="DDC117Reference", mappedBy="source", cascade={"remove"})
     */
    private $references;

    /**
     * @var DDC117ArticleDetails
     * @OneToOne(targetEntity="DDC117ArticleDetails", mappedBy="article", cascade={"persist", "remove"})
     */
    private $details;

    /**
     * @psalm-var Collection<int, DDC117Translation>
     * @OneToMany(targetEntity="DDC117Translation", mappedBy="article", cascade={"persist", "remove"})
     */
    private $translations;

    /**
     * @var Collection<int, DDC117Translation>
     * @OneToMany(targetEntity="DDC117Link", mappedBy="source", indexBy="target_id", cascade={"persist", "remove"})
     */
    private $links;

    public function __construct(string $title)
    {
        $this->title        = $title;
        $this->references   = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function setDetails(DDC117ArticleDetails $details): void
    {
        $this->details = $details;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function addReference(DDC117Reference $reference): void
    {
        $this->references[] = $reference;
    }

    public function references(): Collection
    {
        return $this->references;
    }

    public function addTranslation(string $language, string $title): void
    {
        $this->translations[] = new DDC117Translation($this, $language, $title);
    }

    public function getText(): string
    {
        return $this->details->getText();
    }

    public function getDetails(): DDC117ArticleDetails
    {
        return $this->details;
    }

    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function resetText(): void
    {
        $this->details = null;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }
}
