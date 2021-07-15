<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use function time;
use function uniqid;

class Lock
{
    /** @var string */
    public $value;

    /** @var int */
    public $time;

    public function __construct(string $value, ?int $time = null)
    {
        $this->value = $value;
        $this->time  = $time ?: time();
    }

    /**
     * @return Lock
     */
    public static function createLockRead()
    {
        return new self(uniqid((string) time(), true));
    }
}
