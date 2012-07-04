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

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Common\Persistence\Mapping\ClassMetadata,
    Doctrine\Common\Persistence\Mapping\Driver\FileDriver;

/**
 * The PHPDriver includes php files which just populate ClassMetadata
 * instances with plain php code
 *
 * @license 	http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    	www.doctrine-project.org
 * @since   	2.0
 * @version     $Revision$
 * @author		Benjamin Eberlei <kontakt@beberlei.de>
 * @author		Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org>
 */
class PHPDriver extends FileDriver
{
    const DEFAULT_FILE_EXTENSION = '.php';

    /**
     *
     * @var ClassMetadata
     */
    protected $_metadata;
    
    /**
     * {@inheritDoc}
     */
    public function __construct($locator, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        parent::__construct($locator, $fileExtension);
    }

    /**
     * {@inheritDoc}
     */
    public function loadMetadataForClass($className, ClassMetadata $metadata)
    {
        $this->_metadata = $metadata;
        $this->getElement($className);
    }

    /**
     * {@inheritDoc}
     */
    protected function loadMappingFile($file)
    {
        $result = array();
        $metadata = $this->_metadata;
        include $file;
        // @todo cannot assume that the only loaded metadata is $metadata. Some
        // decision about the preferred approach should be taken
        $result[$metadata->getName()] = $metadata;
        return $result;
    }
}