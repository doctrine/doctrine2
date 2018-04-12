<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;


use Doctrine\ORM\Utility\StaticClassNameConverter;

class MetadataCollection
{

    /**
     * @var ClassMetadata[]
     */
    private $metadata;

    private function __construct(array $metadata)
    {
        $metadataByClassName = array_combine(
            array_map(function (ClassMetadata $metadata) { return $metadata->getClassName(); }, $metadata),
            $metadata
        );
        $this->metadata = $metadataByClassName;
    }

    public function get(string $name) : ClassMetadata
    {
        $name = StaticClassNameConverter::getRealClass($name);
        if(!isset($this->metadata[$name])){
            throw new \Exception('No metadata found for ' . $name);
        }
        return $this->metadata[$name];
    }

    public function has(string $name) : bool
    {
        $name = StaticClassNameConverter::getRealClass($name);
        return isset($this->metadata[$name]);
    }

    public static function fromClassMetadatas(ClassMetadata $firstClass, ClassMetadata ...$otherClasses)
    {
        $otherClasses[] = $firstClass;
        return new self($otherClasses);
    }
}
