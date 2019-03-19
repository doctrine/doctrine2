<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

require_once __DIR__ . '/../../TestInit.php';

class CustomFunctionsTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function testCustomFunctionDefinedWithCallback() : void
    {
        $user           = new CmsUser();
        $user->name     = 'Bob';
        $user->username = 'Dylan';
        $this->em->persist($user);
        $this->em->flush();

        // Instead of defining the function with the class name, we use a callback
        $this->em->getConfiguration()->addCustomStringFunction('FOO', static function ($funcName) {
            return new NoOp($funcName);
        });
        $this->em->getConfiguration()->addCustomNumericFunction('BAR', static function ($funcName) {
            return new NoOp($funcName);
        });

        $query = $this->em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u'
            . ' WHERE FOO(u.name) = \'Bob\''
            . ' AND BAR(1) = 1');

        $users = $query->getResult();

        self::assertCount(1, $users);
        self::assertSame($user, $users[0]);
    }

    public function testCustomFunctionOverride() : void
    {
        $user           = new CmsUser();
        $user->name     = 'Bob';
        $user->username = 'Dylan';

        $this->em->persist($user);
        $this->em->flush();

        $this->em->getConfiguration()->addCustomStringFunction('COUNT', 'Doctrine\Tests\ORM\Functional\CustomCount');

        $query = $this->em->createQuery('SELECT COUNT(DISTINCT u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u');

        $usersCount = $query->getSingleScalarResult();

        self::assertEquals(1, $usersCount);
    }
}

class NoOp extends FunctionNode
{
    /** @var PathExpression */
    private $field;

    public function parse(Parser $parser)
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);
        $this->field = $parser->ArithmeticPrimary();
        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker)
    {
        return $this->field->dispatch($sqlWalker);
    }
}

class CustomCount extends FunctionNode
{
    /** @var AggregateExpression */
    private $aggregateExpression;

    public function parse(Parser $parser) : void
    {
        $this->aggregateExpression = $parser->AggregateExpression();
    }

    public function getSql(SqlWalker $sqlWalker) : string
    {
        return $this->aggregateExpression->dispatch($sqlWalker);
    }
}
