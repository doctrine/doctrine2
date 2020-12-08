<?php

declare(strict_types=1);

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;

/**
 * Caching to an entity or a collection.
 *
 * @Annotation
 * @Target({"CLASS","PROPERTY"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Cache implements Annotation, NamedArgumentConstructorAnnotation
{
    /**
     * @Enum({"READ_ONLY", "NONSTRICT_READ_WRITE", "READ_WRITE"})
     * @var string The concurrency strategy.
     */
    public $usage = 'READ_ONLY';

    /** @var string Cache region name. */
    public $region;

    public function __construct(string $usage = 'READ_ONLY', ?string $region = null, $value = null)
    {
        $this->usage  = $value ?: $usage;
        $this->region = $region;
    }
}
