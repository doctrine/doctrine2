<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

use function is_string;

#[Group('DDC-2984')]
class DDC2984Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType('ddc2984_domain_user_id', DDC2984UserIdCustomDbalType::class);

        $this->createSchemaForModels(DDC2984User::class);
    }

    public function testIssue(): void
    {
        $user = new DDC2984User(new DDC2984DomainUserId('unique_id_within_a_vo'));
        $user->applyName('Alex');

        $this->_em->persist($user);
        $this->_em->flush();

        $repository = $this->_em->getRepository(__NAMESPACE__ . '\DDC2984User');

        $sameUser = $repository->find(new DDC2984DomainUserId('unique_id_within_a_vo'));

        //Until know, everything works as expected
        self::assertTrue($user->sameIdentityAs($sameUser));

        $this->_em->clear();

        //After clearing the identity map, the UnitOfWork produces the warning described in DDC-2984
        $equalUser = $repository->find(new DDC2984DomainUserId('unique_id_within_a_vo'));

        self::assertNotSame($user, $equalUser);
        self::assertTrue($user->sameIdentityAs($equalUser));
    }
}

#[Table(name: 'users')]
#[Entity]
class DDC2984User
{
    #[Column(type: 'string', length: 50)]
    private string|null $name = null;

    public function __construct(
        #[Id]
        #[Column(type: 'ddc2984_domain_user_id', length: 255)]
        #[GeneratedValue(strategy: 'NONE')]
        private DDC2984DomainUserId $userId,
    ) {
    }

    public function userId(): DDC2984DomainUserId
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function applyName(string $name): void
    {
        $this->name = $name;
    }

    public function sameIdentityAs(DDC2984User $other): bool
    {
        return $this->userId()->sameValueAs($other->userId());
    }
}

/**
 * DDC2984DomainUserId ValueObject
 */
class DDC2984DomainUserId implements Stringable
{
    public function __construct(private string $userIdString)
    {
    }

    public function toString(): string
    {
        return $this->userIdString;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function sameValueAs(DDC2984DomainUserId $other): bool
    {
        return $this->toString() === $other->toString();
    }
}

class DDC2984UserIdCustomDbalType extends StringType
{
    private const string TYPE_NAME = 'ddc2984_domain_user_id';

    public function getName(): string
    {
        return self::TYPE_NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): DDC2984DomainUserId|null
    {
        return ! empty($value)
            ? new DDC2984DomainUserId($value)
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (empty($value)) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (! $value instanceof DDC2984DomainUserId) {
            throw ConversionException::conversionFailed($value, self::TYPE_NAME);
        }

        return $value->toString();
    }
}
