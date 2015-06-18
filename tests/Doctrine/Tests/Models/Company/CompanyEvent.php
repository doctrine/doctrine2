<?php

namespace Doctrine\Tests\Models\Company;

/**
 * @Entity @Table(name="company_events")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="event_type", type="string")
 * @DiscriminatorMap({"auction"="CompanyAuction", "raffle"="CompanyRaffle"})
 */
abstract class CompanyEvent {
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @ManyToOne(targetEntity="CompanyOrganization", inversedBy="events", cascade={"persist"})
     * @JoinColumn(name="org_id", referencedColumnName="id")
     */
     private $organization;

    /**
     * @Version @Column(type="integer")
     * @var int
     */
    protected $version;

    /**
     * @VersionBump
     * @var boolean
     */
    protected $bflag;


    public function getId() {
        return $this->id;
    }

    public function getOrganization() {
        return $this->organization;
    }

    public function setOrganization(CompanyOrganization $org) {
        $this->organization = $org;
    }

    public function setVersionBump($val) {
        $this->bflag = (bool)$val;
    }

    public function getVersionBump() {
        return $this->bflag;
    }

    public function getVersion() {
        return $this->version;
    }

}