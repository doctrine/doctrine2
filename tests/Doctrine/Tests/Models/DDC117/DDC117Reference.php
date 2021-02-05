<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC117;

use DateTime;

/**
 * @Entity
 */
class DDC117Reference
{
    /**
     * @Id
     * @ManyToOne(targetEntity="DDC117Article", inversedBy="references")
     * @JoinColumn(name="source_id", referencedColumnName="article_id")
     */
    private $source;

    /**
     * @Id
     * @ManyToOne(targetEntity="DDC117Article")
     * @JoinColumn(name="target_id", referencedColumnName="article_id")
     */
    private $target;

    /** @column(type="string") */
    private $description;

    /** @column(type="datetime") */
    private $created;

    public function __construct($source, $target, $description)
    {
        $source->addReference($this);
        $target->addReference($this);

        $this->source      = $source;
        $this->target      = $target;
        $this->description = $description;
        $this->created     = new DateTime('now');
    }

    public function source()
    {
        return $this->source;
    }

    public function target()
    {
        return $this->target;
    }

    public function setDescription($desc): void
    {
        $this->description = $desc;
    }

    public function getDescription()
    {
        return $this->description;
    }
}
