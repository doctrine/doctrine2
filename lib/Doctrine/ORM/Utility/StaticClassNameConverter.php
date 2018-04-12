<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use ProxyManager\Configuration;
use ProxyManager\Inflector\ClassNameInflectorInterface;
use function get_class;
use function ltrim;

/**
 * This class provides utility method to retrieve class names, and to convert
 * proxy class names to original class names
 *
 * @internal do not use in your own codebase: no BC compliance on this class
 */
abstract class StaticClassNameConverter
{
    /** @var ClassNameInflectorInterface|null */
    private static $classNameInflector;

    final private function __construct()
    {
    }

    /**
     * Gets the real class name of a class name that could be a proxy.
     */
    public static function getRealClass(string $class) : string
    {
        $class = ltrim($class, '\\');
        $inflector                       = self::$classNameInflector
            ?? self::$classNameInflector = (new Configuration())->getClassNameInflector();

        return $inflector->getUserClassName($class);
    }

    /**
     * Gets the real class name of an object (even if its a proxy).
     */
    public static function getClass(object $object) : string
    {
        $inflector                       = self::$classNameInflector
            ?? self::$classNameInflector = (new Configuration())->getClassNameInflector();

        return $inflector->getUserClassName(get_class($object));
    }
}
