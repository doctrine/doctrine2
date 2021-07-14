<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function is_string;

/**
 * @group DDC-2984
 */
class DDC2984Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Type::hasType('ddc2984_domain_user_id')) {
            Type::addType(
                'ddc2984_domain_user_id',
                DDC2984UserIdCustomDbalType::class
            );
        }

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2984User::class),
                ]
            );
        } catch (Exception $e) {
            // no action needed - schema seems to be already in place
        }
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
        $this->assertTrue($user->sameIdentityAs($sameUser));

        $this->_em->clear();

        //After clearing the identity map, the UnitOfWork produces the warning described in DDC-2984
        $equalUser = $repository->find(new DDC2984DomainUserId('unique_id_within_a_vo'));

        $this->assertNotSame($user, $equalUser);
        $this->assertTrue($user->sameIdentityAs($equalUser));
    }
}

/** @Entity @Table(name="users") */
class DDC2984User
{
    /**
     * @Id @Column(type="ddc2984_domain_user_id")
     * @GeneratedValue(strategy="NONE")
     * @var DDC2984DomainUserId
     */
    private $userId;

    /**
     * @var string
     * @Column(type="string", length=50)
     */
    private $name;

    public function __construct(DDC2984DomainUserId $aUserId)
    {
        $this->userId = $aUserId;
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
class DDC2984DomainUserId
{
    /** @var string */
    private $userIdString;

    public function __construct(string $aUserIdString)
    {
        $this->userIdString = $aUserIdString;
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
    public function getName(): string
    {
        return 'ddc2984_domain_user_id';
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return ! empty($value)
            ? new DDC2984DomainUserId($value)
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if (empty($value)) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (! $value instanceof DDC2984DomainUserId) {
            throw ConversionException::conversionFailed($value, $this->getName());
        }

        return $value->toString();
    }
}
