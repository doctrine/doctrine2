<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

class FieldBuilderTest extends OrmTestCase
{
    #[TestWith([null, ClassMetadata::GENERATOR_TYPE_CUSTOM])]
    #[TestWith(['NONE', ClassMetadata::GENERATOR_TYPE_NONE])]
    #[TestWith(['AUTO', ClassMetadata::GENERATOR_TYPE_AUTO])]
    #[TestWith(['SEQUENCE', ClassMetadata::GENERATOR_TYPE_SEQUENCE])]
    #[TestWith(['IDENTITY', ClassMetadata::GENERATOR_TYPE_IDENTITY])]
    #[TestWith(['CUSTOM', ClassMetadata::GENERATOR_TYPE_CUSTOM])]
    public function testCustomIdGeneratorStrategy(string|null $strategy, int $expectedStrategy): void
    {
        $metadata = new ClassMetadata(CmsUser::class);
        $field    = (new ClassMetadataBuilder($metadata))->createField('id', 'integer')->makePrimaryKey();

        if ($strategy !== null) {
            $field->generatedValue($strategy);
        }

        $field->setCustomIdGenerator(stdClass::class)->build();

        self::assertSame($expectedStrategy, $metadata->generatorType);
        self::assertSame(['class' => stdClass::class], $metadata->customGeneratorDefinition);
    }

    public function testCustomIdGeneratorCanBeSet(): void
    {
        $cmBuilder = new ClassMetadataBuilder(new ClassMetadata(CmsUser::class));

        $fieldBuilder = $cmBuilder->createField('aField', 'string');

        $fieldBuilder->generatedValue('CUSTOM');
        $fieldBuilder->setCustomIdGenerator(stdClass::class);

        $fieldBuilder->build();

        self::assertEquals(ClassMetadata::GENERATOR_TYPE_CUSTOM, $cmBuilder->getClassMetadata()->generatorType);
        self::assertEquals(['class' => stdClass::class], $cmBuilder->getClassMetadata()->customGeneratorDefinition);
    }
}
