<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console;

use ArrayIterator;
use Countable;
use Doctrine\Persistence\Mapping\ClassMetadata;
use FilterIterator;
use RuntimeException;

use function assert;
use function count;
use function iterator_to_array;
use function preg_match;
use function sprintf;

/**
 * Used by CLI Tools to restrict entity-based commands to given patterns.
 *
 * @link        www.doctrine-project.com
 */
class MetadataFilter extends FilterIterator implements Countable
{
    /** @var mixed[] */
    private $filter = [];

    /**
     * Filter Metadatas by one or more filter options.
     *
     * @param ClassMetadata[] $metadatas
     * @param string[]|string $filter
     *
     * @return ClassMetadata[]
     */
    public static function filter(array $metadatas, $filter)
    {
        $metadatas = new MetadataFilter(new ArrayIterator($metadatas), $filter);

        return iterator_to_array($metadatas);
    }

    /**
     * @param mixed[]|string $filter
     */
    public function __construct(ArrayIterator $metadata, $filter)
    {
        $this->filter = (array) $filter;

        parent::__construct($metadata);
    }

    /**
     * @return bool
     */
    public function accept()
    {
        if (count($this->filter) === 0) {
            return true;
        }

        $it       = $this->getInnerIterator();
        $metadata = $it->current();

        foreach ($this->filter as $filter) {
            $pregResult = preg_match('/' . $filter . '/', $metadata->getName());

            if ($pregResult === false) {
                throw new RuntimeException(
                    sprintf("Error while evaluating regex '/%s/'.", $filter)
                );
            }

            if ($pregResult) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ArrayIterator<int, ClassMetadata>
     */
    public function getInnerIterator()
    {
        $innerIterator = parent::getInnerIterator();

        assert($innerIterator instanceof ArrayIterator);

        return $innerIterator;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->getInnerIterator());
    }
}
