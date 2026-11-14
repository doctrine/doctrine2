<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH9505;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'gh9505_readonly_object_id')]
class EntityWithReadonlyObjectIdentifier
{
    #[Column(type: GH9505ObjectIdType::NAME)]
    #[Id]
    private readonly GH9505ObjectId $id;

    #[Column(type: 'string')]
    private string $name;

    public function __construct(GH9505ObjectId $id, string $name)
    {
        $this->id   = $id;
        $this->name = $name;
    }

    public function getId(): GH9505ObjectId
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
