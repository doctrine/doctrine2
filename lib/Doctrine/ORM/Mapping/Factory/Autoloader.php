<?php


declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Factory;

use InvalidArgumentException;

class Autoloader
{
    /**
     * Resolves ClassMetadata class name to a filename based on the following pattern.
     *
     * 1. Remove Metadata namespace from class name.
     * 2. Remove namespace separators from remaining class name.
     * 3. Return PHP filename from metadata-dir with the result from 2.
     *
     * @param string $metadataDir
     * @param string $metadataNamespace
     * @param string $className
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public static function resolveFile(string $metadataDir, string $metadataNamespace, string $className) : string
    {
        if (strpos($className, $metadataNamespace) !== 0) {
            throw new InvalidArgumentException(
                sprintf('The class "%s" is not part of the metadata namespace "%s"', $className, $metadataNamespace)
            );
        }

        // remove metadata namespace from class name
        $classNameRelativeToMetadataNamespace = substr($className, strlen($metadataNamespace));

        // remove namespace separators from remaining class name
        $fileName = str_replace('\\', '', $classNameRelativeToMetadataNamespace);

        return $metadataDir . DIRECTORY_SEPARATOR . $fileName . '.php';
    }

    /**
     * Registers and returns autoloader callback for the given metadata dir and namespace.
     *
     * @param string        $metadataDir
     * @param string        $metadataNamespace
     * @param callable|null $notFoundCallback Invoked when the proxy file is not found.
     *
     * @return \Closure
     *
     * @throws InvalidArgumentException
     */
    public static function register(
        string $metadataDir,
        string $metadataNamespace,
        callable $notFoundCallback = null
    ) : \Closure
    {
        $metadataNamespace = ltrim($metadataNamespace, '\\');

        if (! ($notFoundCallback === null || is_callable($notFoundCallback))) {
            $type = is_object($notFoundCallback) ? get_class($notFoundCallback) : gettype($notFoundCallback);

            throw new InvalidArgumentException(
                sprintf('Invalid \$notFoundCallback given: must be a callable, "%s" given', $type)
            );
        }

        $autoloader = function ($className) use ($metadataDir, $metadataNamespace, $notFoundCallback) {
            if (strpos($className, $metadataNamespace) === 0) {
                $file = Autoloader::resolveFile($metadataDir, $metadataNamespace, $className);

                if ($notFoundCallback && ! file_exists($file)) {
                    call_user_func($notFoundCallback, $metadataDir, $metadataNamespace, $className);
                }

                require $file;
            }
        };

        spl_autoload_register($autoloader);

        return $autoloader;
    }
}
