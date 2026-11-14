<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

enum ProductStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
}
