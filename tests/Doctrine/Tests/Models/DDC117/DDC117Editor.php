<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC117;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * @Entity
 */
class DDC117Editor
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string|null
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC117Translation>
     * @ManyToMany(targetEntity="DDC117Translation", inversedBy="reviewedByEditors")
     * @JoinTable(
     *   inverseJoinColumns={
     *     @JoinColumn(name="article_id", referencedColumnName="article_id"),
     *     @JoinColumn(name="language", referencedColumnName="language")
     *   },
     *   joinColumns={
     *     @JoinColumn(name="editor_id", referencedColumnName="id")
     *   }
     * )
     */
    public $reviewingTranslations;

    /**
     * @var DDC117Translation
     * @ManyToOne(targetEntity="DDC117Translation", inversedBy="lastTranslatedBy")
     * @JoinColumns({
     *   @JoinColumn(name="lt_article_id", referencedColumnName="article_id"),
     *   @JoinColumn(name="lt_language", referencedColumnName="language")
     * })
     */
    public $lastTranslation;

    public function __construct(?string $name = '')
    {
        $this->name                  = $name;
        $this->reviewingTranslations = new ArrayCollection();
    }

    public function addLastTranslation(DDC117Translation $t): void
    {
        $this->lastTranslation = $t;
        $t->lastTranslatedBy[] = $this;
    }
}
