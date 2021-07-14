<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\Models\Quote\User as QuotedUser;
use Doctrine\Tests\OrmFunctionalTestCase;

use function sprintf;

final class GH7012Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('quote');

        parent::setUp();

        $this->setUpEntitySchema([GH7012UserData::class]);
    }

    /**
     * @group GH-7012
     */
    public function testUpdateEntityWithIdentifierAssociationWithQuotedJoinColumn(): void
    {
        $user       = new QuotedUser();
        $user->name = 'John Doe';

        $this->_em->persist($user);
        $this->_em->flush();

        $userData = new GH7012UserData($user, '123456789');

        $this->_em->persist($userData);
        $this->_em->flush();

        $userData->name = '4321';
        $this->_em->flush();

        $platform         = $this->_em->getConnection()->getDatabasePlatform();
        $quotedTableName  = $platform->quoteIdentifier('quote-user-data');
        $quotedColumn     = $platform->quoteIdentifier('name');
        $quotedIdentifier = $platform->quoteIdentifier('user-id');

        self::assertNotEquals('quote-user-data', $quotedTableName);
        self::assertNotEquals('name', $quotedColumn);
        self::assertNotEquals('user-id', $quotedIdentifier);

        $queries = $this->_sqlLoggerStack->queries;

        $this->assertSQLEquals(
            sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $quotedTableName, $quotedColumn, $quotedIdentifier),
            $queries[$this->_sqlLoggerStack->currentQuery - 1]['sql']
        );
    }
}


/**
 * @Entity
 * @Table(name="`quote-user-data`")
 */
class GH7012UserData
{
    /**
     * @var QuotedUser
     * @Id
     * @OneToOne(targetEntity=Doctrine\Tests\Models\Quote\User::class)
     * @JoinColumn(name="`user-id`", referencedColumnName="`user-id`", onDelete="CASCADE")
     */
    public $user;

    /**
     * @var string
     * @Column(type="string", name="`name`")
     */
    public $name;

    public function __construct(QuotedUser $user, string $name)
    {
        $this->user = $user;
        $this->name = $name;
    }
}
