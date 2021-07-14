<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DirectoryTree;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 */
class Directory extends AbstractContentItem
{
    /**
     * @var string
     * @Column(type="string")
     */
    protected $path;

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
