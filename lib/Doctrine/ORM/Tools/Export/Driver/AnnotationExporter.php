<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Export\Driver;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\EntityGenerator;
use RuntimeException;

use function str_replace;

/**
 * ClassMetadata exporter for PHP classes with annotations.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class AnnotationExporter extends AbstractExporter
{
    /** @var string */
    protected $_extension = '.php';

    /** @var EntityGenerator|null */
    private $_entityGenerator;

    /**
     * {@inheritdoc}
     */
    public function exportClassMetadata(ClassMetadataInfo $metadata)
    {
        if (! $this->_entityGenerator) {
            throw new RuntimeException('For the AnnotationExporter you must set an EntityGenerator instance with the setEntityGenerator() method.');
        }

        $this->_entityGenerator->setGenerateAnnotations(true);
        $this->_entityGenerator->setGenerateStubMethods(false);
        $this->_entityGenerator->setRegenerateEntityIfExists(false);
        $this->_entityGenerator->setUpdateEntityIfExists(false);

        return $this->_entityGenerator->generateEntityClass($metadata);
    }

    /**
     * @return string
     */
    protected function _generateOutputPath(ClassMetadataInfo $metadata)
    {
        return $this->_outputDir . '/' . str_replace('\\', '/', $metadata->name) . $this->_extension;
    }

    /**
     * @return void
     */
    public function setEntityGenerator(EntityGenerator $entityGenerator)
    {
        $this->_entityGenerator = $entityGenerator;
    }
}
