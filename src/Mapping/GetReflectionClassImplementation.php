<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Persistence\Mapping\StaticReflectionService;
use ReflectionClass;

use function class_exists;

if (! class_exists(StaticReflectionService::class)) {
    trait GetReflectionClassImplementation
    {
        public function getReflectionClass(): ReflectionClass
        {
            return $this->reflClass;
        }
    }
} else {
    trait GetReflectionClassImplementation
    {
        /**
         * {@inheritDoc}
         *
         * Can return null when using static reflection, in violation of the LSP
         */
        public function getReflectionClass(): ReflectionClass
        {
            return $this->reflClass;
        }
    }
}
