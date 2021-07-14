<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\TypedProperties;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;

/**
 * @ORM\Embeddable()
 */
#[ORM\Embeddable]
class Contact
{
    /** @Column() */
    #[ORM\Column]
    public ?string $email = null;
}
