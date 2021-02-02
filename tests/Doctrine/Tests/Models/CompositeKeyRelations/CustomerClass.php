<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyRelations;

/**
 * @Entity
 */
class CustomerClass
{
    /**
     * @Id @Column(type="string")
     */
    public string $companyCode;

    /**
     * @Id @Column(type="string")
     */
    public string $code;

    /**
     * @Column(type="string")
     */
    public string $name;
}
