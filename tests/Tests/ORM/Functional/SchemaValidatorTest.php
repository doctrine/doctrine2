<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\DbalTypes\UpperCaseStringType;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function array_keys;
use function implode;

/**
 * Test the validity of all modelsets
 */
#[Group('DDC-1601')]
class SchemaValidatorTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        self::registerDbalType(CustomIdObjectType::NAME, CustomIdObjectType::class);
        self::registerDbalType(UpperCaseStringType::NAME, UpperCaseStringType::class);
        self::registerDbalType(NegativeToPositiveType::NAME, NegativeToPositiveType::class);

        parent::setUp();
    }

    public static function dataValidateModelSets(): array
    {
        $modelSets = [];

        foreach (array_keys(self::$modelSets) as $modelSet) {
            $modelSets[$modelSet] = [$modelSet];
        }

        return $modelSets;
    }

    #[DataProvider('dataValidateModelSets')]
    public function testValidateModelSets(string $modelSet): void
    {
        $validator = new SchemaValidator($this->_em);
        $classes   = [];

        foreach (self::$modelSets[$modelSet] as $className) {
            $classes[] = $this->_em->getClassMetadata($className);
        }

        foreach ($classes as $class) {
            $ce = $validator->validateClass($class);

            self::assertEmpty($ce, 'Invalid Modelset: ' . $modelSet . ' class ' . $class->name . ': ' . implode("\n", $ce));
        }
    }
}
