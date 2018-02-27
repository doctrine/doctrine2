<?php

namespace Doctrine\Tests\Models\DDC1476;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity()
 */
class DDC1476EntityWithDefaultFieldType
{

    /**
     * @Id
     * @Column()
     * @GeneratedValue("NONE")
     */
    protected $id;

    /** @column() */
    protected $name;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
           'id'         => true,
           'fieldName'  => 'id',
            ]
        );
        $metadata->mapField(
            [
           'fieldName'  => 'name',
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }

}
