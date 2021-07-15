<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;

use function get_class;
use function get_object_vars;
use function is_array;
use function is_object;
use function str_repeat;
use function var_export;

use const PHP_EOL;

/**
 * Abstract class of an AST node.
 *
 * @link    www.doctrine-project.org
 */
abstract class Node
{
    /**
     * Double-dispatch method, supposed to dispatch back to the walker.
     *
     * Implementation is not mandatory for all nodes.
     *
     * @param SqlWalker $walker
     *
     * @return string
     *
     * @throws ASTException
     */
    public function dispatch($walker)
    {
        throw ASTException::noDispatchForNode($this);
    }

    /**
     * Dumps the AST Node into a string representation for information purpose only.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->dump($this);
    }

    /**
     * @param object $obj
     *
     * @return string
     */
    public function dump($obj)
    {
        static $ident = 0;

        $str = '';

        if ($obj instanceof Node) {
            $str  .= get_class($obj) . '(' . PHP_EOL;
            $props = get_object_vars($obj);

            foreach ($props as $name => $prop) {
                $ident += 4;
                $str   .= str_repeat(' ', $ident) . '"' . $name . '": '
                      . $this->dump($prop) . ',' . PHP_EOL;
                $ident -= 4;
            }

            $str .= str_repeat(' ', $ident) . ')';
        } elseif (is_array($obj)) {
            $ident += 4;
            $str   .= 'array(';
            $some   = false;

            foreach ($obj as $k => $v) {
                $str .= PHP_EOL . str_repeat(' ', $ident) . '"'
                      . $k . '" => ' . $this->dump($v) . ',';
                $some = true;
            }

            $ident -= 4;
            $str   .= ($some ? PHP_EOL . str_repeat(' ', $ident) : '') . ')';
        } elseif (is_object($obj)) {
            $str .= 'instanceof(' . get_class($obj) . ')';
        } else {
            $str .= var_export($obj, true);
        }

        return $str;
    }
}
