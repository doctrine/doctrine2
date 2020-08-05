<?php
declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\ORM\Query\Parameter;
use Doctrine\Tests\DoctrineTestCase;
use Doctrine\Tests\VerifyDeprecations;

final class ParameterTest extends DoctrineTestCase
{
    use VerifyDeprecations;

    /**
     * @test
     * @group GH6880
     */
    public function deprecationMustBeTriggeredWhenUsingColonInParameterNames() : void
    {
        $this->expectDeprecationMessage('Starting or ending a parameter name with ":" is deprecated since 2.7 and will cause an error in 3.0');
        new Parameter(':user_name', 'Testing');

        $this->expectDeprecationMessage('Starting or ending a parameter name with ":" is deprecated since 2.7 and will cause an error in 3.0');
        new Parameter('user_name:', 'Testing');

        $this->expectDeprecationMessage('Starting or ending a parameter name with ":" is deprecated since 2.7 and will cause an error in 3.0');
        new Parameter(':user_name:', 'Testing');
    }
}
