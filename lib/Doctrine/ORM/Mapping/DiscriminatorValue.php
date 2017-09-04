<?php
namespace Doctrine\ORM\Mapping;

/**
 * @Annotation
 * @Target("CLASS")
 */
final class DiscriminatorValue implements Annotation
{
    /**
     * @var string
     */
    public $value;
}
