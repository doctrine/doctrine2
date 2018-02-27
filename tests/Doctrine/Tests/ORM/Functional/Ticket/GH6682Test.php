<?php

namespace Doctrine\Test\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\OrmFunctionalTestCase;

final class GH6682Test extends OrmFunctionalTestCase
{
    /**
     * @group 6682
     */
    public function testIssue() : void
    {
        $parsedDefinition = [
            'sequenceName'   => 'test_sequence',
            'allocationSize' => '',
            'initialValue'   => '',
        ];

        $classMetadataInfo = new ClassMetadata('test_entity');
        $classMetadataInfo->setSequenceGeneratorDefinition($parsedDefinition);

        self::assertSame(
            ['sequenceName' => 'test_sequence', 'allocationSize' => '1', 'initialValue' => '1'],
            $classMetadataInfo->sequenceGeneratorDefinition
        );
    }
}
