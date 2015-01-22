<?php

namespace Doctrine\Tests\Models\DDC3346;

/**
 * @Entity
 * @Table(name="ddc3346_users")
 */
class DDC3346Author
{
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
    /**
     * @Column(type="string", length=50, nullable=true)
     */
    public $status;
    /**
     * @Column(type="string", length=255, unique=true)
     */
    public $username;
    /**
     * @Column(type="string", length=255)
     */
    public $name;
    /**
     * @OneToMany(targetEntity="DDC3346Article", mappedBy="user", fetch="EAGER", cascade={"detach"})
     */
    public $articles;
}
