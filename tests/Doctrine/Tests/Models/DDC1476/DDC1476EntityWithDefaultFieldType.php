<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC1476;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity()
 */
#[ORM\Entity]
class DDC1476EntityWithDefaultFieldType
{
    /**
     * @var int
     * @Id
     * @Column()
     * @GeneratedValue("NONE")
     */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue(strategy: 'NONE')]
    protected $id;

    /**
     * @var string
     * @Column()
     */
    #[ORM\Column]
    protected $name;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id'         => true,
                'fieldName'  => 'id',
            ]
        );
        $metadata->mapField(
            ['fieldName' => 'name']
        );

        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
    }
}
