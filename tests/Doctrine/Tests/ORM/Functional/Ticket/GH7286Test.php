<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\Tests\OrmFunctionalTestCase;

final class GH7286Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                GH7286Entity::class,
            ]
        );

        $this->_em->persist(new GH7286Entity('foo', 1));
        $this->_em->persist(new GH7286Entity('foo', 2));
        $this->_em->persist(new GH7286Entity('bar', 3));
        $this->_em->persist(new GH7286Entity(null, 4));
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testAggregateExpressionInFunction(): void
    {
        $query = $this->_em->createQuery(
            'SELECT CONCAT(e.type, MIN(e.version)) pair'
            . ' FROM ' . GH7286Entity::class . ' e'
            . ' WHERE e.type IS NOT NULL'
            . ' GROUP BY e.type'
            . ' ORDER BY e.type'
        );

        self::assertSame(
            [
                ['pair' => 'bar3'],
                ['pair' => 'foo1'],
            ],
            $query->getArrayResult()
        );
    }

    /**
     * @group DDC-1091
     */
    public function testAggregateFunctionInCustomFunction(): void
    {
        $this->_em->getConfiguration()->addCustomStringFunction('CC', GH7286CustomConcat::class);

        $query = $this->_em->createQuery(
            'SELECT CC(e.type, MIN(e.version)) pair'
            . ' FROM ' . GH7286Entity::class . ' e'
            . ' WHERE e.type IS NOT NULL AND e.type != :type'
            . ' GROUP BY e.type'
        );
        $query->setParameter('type', 'bar');

        self::assertSame(
            ['pair' => 'foo1'],
            $query->getSingleResult()
        );
    }
}

/**
 * @Entity
 */
class GH7286Entity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     * @var int
     */
    public $id;

    /**
     * @Column(nullable=true)
     * @var string|null
     */
    public $type;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $version;

    public function __construct(?string $type, int $version)
    {
        $this->type    = $type;
        $this->version = $version;
    }
}

class GH7286CustomConcat extends FunctionNode
{
    /** @var Node */
    private $first;

    /** @var Node */
    private $second;

    public function parse(Parser $parser): void
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->first = $parser->StringPrimary();
        $parser->match(Lexer::T_COMMA);
        $this->second = $parser->StringPrimary();

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        return $walker->getConnection()->getDatabasePlatform()->getConcatExpression(
            $this->first->dispatch($walker),
            $this->second->dispatch($walker)
        );
    }
}
