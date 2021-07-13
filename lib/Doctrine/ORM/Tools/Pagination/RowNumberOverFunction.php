<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\OrderByClause;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Tools\Pagination\Exception\RowNumberOverFunctionNotEnabled;

use function trim;

/**
 * RowNumberOverFunction
 *
 * Provides ROW_NUMBER() OVER(ORDER BY...) construct for use in LimitSubqueryOutputWalker
 */
class RowNumberOverFunction extends FunctionNode
{
    /** @var OrderByClause */
    public $orderByClause;

    /**
     * @override
     * @inheritdoc
     */
    public function getSql(SqlWalker $sqlWalker)
    {
        return 'ROW_NUMBER() OVER(' . trim($sqlWalker->walkOrderByClause(
            $this->orderByClause
        )) . ')';
    }

    /**
     * @throws RowNumberOverFunctionNotEnabled
     *
     * @override
     * @inheritdoc
     */
    public function parse(Parser $parser)
    {
        throw RowNumberOverFunctionNotEnabled::create();
    }
}
