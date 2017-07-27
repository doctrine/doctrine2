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

declare(strict_types=1);

namespace Doctrine\ORM\Configuration;

use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\Proxy\Factory\ProxyResolver;

/**
 * Configuration container for proxy manager options of Doctrine.
 *
 * @package Doctrine\ORM\Configuration
 * @since 3.0
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 */
class ProxyConfiguration
{
    /**
     * @var ProxyResolver
     */
    private $resolver;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $autoGenerate = ProxyFactory::AUTOGENERATE_ALWAYS;

    /**
     * @return ProxyResolver
     */
    public function getResolver(): ProxyResolver
    {
        return $this->resolver;
    }

    /**
     * @param ProxyResolver $resolver
     */
    public function setResolver(ProxyResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return string
     */
    public function getNamespace() : string
    {
        return $this->namespace;
    }

    /**
     * @param string $namespace
     */
    public function setNamespace(string $namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * @return string
     */
    public function getDirectory() : string
    {
        return $this->directory;
    }

    /**
     * @param string $directory
     */
    public function setDirectory(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * @todo guilhermeblanco Get rid of this method and associated constants. Use the generator strategy instead.
     *
     * @return int
     */
    public function getAutoGenerate() : int
    {
        return $this->autoGenerate;
    }

    /**
     * @param int $autoGenerate
     */
    public function setAutoGenerate(int $autoGenerate)
    {
        $this->autoGenerate = $autoGenerate;
    }
}
