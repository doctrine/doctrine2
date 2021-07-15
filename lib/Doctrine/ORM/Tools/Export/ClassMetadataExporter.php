<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Export;

use Doctrine\Deprecations\Deprecation;

/**
 * Class used for converting your mapping information between the
 * supported formats: yaml, xml, and php/annotation.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class ClassMetadataExporter
{
    /** @var array<string,string> */
    private static $_exporterDrivers = [
        'xml' => Driver\XmlExporter::class,
        'yaml' => Driver\YamlExporter::class,
        'yml' => Driver\YamlExporter::class,
        'php' => Driver\PhpExporter::class,
        'annotation' => Driver\AnnotationExporter::class,
    ];

    public function __construct()
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8458',
            '%s is deprecated with no replacement',
            self::class
        );
    }

    /**
     * Registers a new exporter driver class under a specified name.
     *
     * @param string $name
     * @param string $class
     *
     * @return void
     */
    public static function registerExportDriver($name, $class)
    {
        self::$_exporterDrivers[$name] = $class;
    }

    /**
     * Gets an exporter driver instance.
     *
     * @param string      $type The type to get (yml, xml, etc.).
     * @param string|null $dest The directory where the exporter will export to.
     *
     * @return Driver\AbstractExporter
     *
     * @throws ExportException
     */
    public function getExporter($type, $dest = null)
    {
        if (! isset(self::$_exporterDrivers[$type])) {
            throw ExportException::invalidExporterDriverType($type);
        }

        $class = self::$_exporterDrivers[$type];

        return new $class($dest);
    }
}
