<?php

namespace Doctrine\Tests\ORM\Query;

use Doctrine\ORM\Query\Parameter;
use PHPUnit\Framework\TestCase;

class ParameterTest extends TestCase
{
    /**
     * @dataProvider getParameterNamesStartingOrEndingWithAColon
     */
    public function testParameterNameStartingOrEndingWithAColon(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A parameter name cannot start or end with ":".');

        new Parameter($name, 'value');
    }

    /**
     * @return array
     */
    public function getParameterNamesStartingOrEndingWithAColon(): array
    {
        return array(
            array(':name'),
            array('::name'),
            array('name:'),
            array('name::'),
            array(':name:'),
            array('::name::')
        );
    }
}
