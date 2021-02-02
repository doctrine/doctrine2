<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyRelations;

/**
 * @Entity
 */
class InvoiceClass
{
    /**
     * @Id @Column(type="string")
     */
    public string $companyCode;

    /**
     * @Id @Column(type="string")
     */
    public string $invoiceNumber;

    /**
     * @ManyToOne(targetEntity="CustomerClass")
     * @JoinColumns({
     *     @JoinColumn(name="companyCode", referencedColumnName="companyCode"),
     *     @JoinColumn(name="customerCode", referencedColumnName="code")
     * })
     */
    public ?CustomerClass $customer;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $customerCode;
}
