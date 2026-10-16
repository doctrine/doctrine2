<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

enum SchemaOperation
{
    case Create;
    case Drop;
    case Update;
}
