<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH11072;

use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Tests\OrmFunctionalTestCase;

final class GH11072Test extends OrmFunctionalTestCase
{
    /** @var SchemaValidator */
    private $validator;

    protected function setUp(): void
    {
        $this->_em       = $this->getTestEntityManager();
        $this->validator = new SchemaValidator($this->_em);
    }

    public function testAcceptsSubsetOfBuiltinTypesWithoutErrors(): void
    {
        $class = $this->_em->getClassMetadata(GH11072EntityBasic::class);
        $ce    = $this->validator->validateClass($class);

        self::assertSame([], $ce);
    }

    public function testAcceptsAdvancedSubsetOfBuiltinTypesWithoutErrors(): void
    {
        $class = $this->_em->getClassMetadata(GH11072EntityAdvanced::class);
        $ce    = $this->validator->validateClass($class);

        self::assertSame([], $ce);
    }
}
