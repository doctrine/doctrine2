<?php

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
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;
use Doctrine\Deprecations\Deprecation;

/**
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target("PROPERTY")
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToMany implements Annotation
{
    /** @var string|null */
    public $targetEntity;

    /** @var string */
    public $mappedBy;

    /** @var string */
    public $inversedBy;

    /** @var array<string> */
    public $cascade;

    /**
     * The fetching strategy to use for the association.
     *
     * @var string
     * @Enum({"LAZY", "EAGER", "EXTRA_LAZY"})
     */
    public $fetch = 'LAZY';

    /** @var bool */
    public $orphanRemoval = false;

    /** @var string */
    public $indexBy;

    public $ignoreDuplicates = false;

    /**
     * @param array<string> $cascade
     */
    public function __construct(
        ?string $targetEntity = null,
        ?string $mappedBy = null,
        ?string $inversedBy = null,
        ?array $cascade = null,
        string $fetch = 'LAZY',
        bool $orphanRemoval = false,
        ?string $indexBy = null,
        bool $ignoreDuplicates = false
    ) {
        if ($targetEntity === null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/issues/8753',
                'Passing no target entity is deprecated.'
            );
        }

        $this->targetEntity  = $targetEntity;
        $this->mappedBy      = $mappedBy;
        $this->inversedBy    = $inversedBy;
        $this->cascade       = $cascade;
        $this->fetch         = $fetch;
        $this->orphanRemoval = $orphanRemoval;
        $this->indexBy       = $indexBy;
        $this->ignoreDuplicates = $ignoreDuplicates;
    }
}
