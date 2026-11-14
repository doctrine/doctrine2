<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueObjects;

class EmbeddableSubclass extends EmbeddableBase
{
    private string|null $extraValue = null;
}
