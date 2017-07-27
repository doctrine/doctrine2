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

namespace Doctrine\ORM\Tools\Export\Driver;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Export\ExportException;

/**
 * Abstract base class which is to be used for the Exporter drivers
 * which can be found in \Doctrine\ORM\Tools\Export\Driver.
 *
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Jonathan Wage <jonwage@gmail.com>
 */
abstract class AbstractExporter
{
    /**
     * @var array
     */
    protected $metadata = [];

    /**
     * @var string|null
     */
    protected $outputDir;

    /**
     * @var string|null
     */
    protected $extension;

    /**
     * @var bool
     */
    protected $overwriteExistingFiles = false;

    /**
     * @param string|null $dir
     */
    public function __construct($dir = null)
    {
        $this->outputDir = $dir;
    }

    /**
     * @param bool $overwrite
     *
     * @return void
     */
    public function setOverwriteExistingFiles($overwrite)
    {
        $this->overwriteExistingFiles = $overwrite;
    }

    /**
     * Converts a single ClassMetadata instance to the exported format
     * and returns it.
     *
     * @param ClassMetadata $metadata
     *
     * @return string
     */
    abstract public function exportClassMetadata(ClassMetadata $metadata);

    /**
     * Sets the array of ClassMetadata instances to export.
     *
     * @param array $metadata
     *
     * @return void
     */
    public function setMetadata(array $metadata)
    {
        $this->metadata = $metadata;
    }

    /**
     * Gets the extension used to generated the path to a class.
     *
     * @return string|null
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * Sets the directory to output the mapping files to.
     *
     *     [php]
     *     $exporter = new XmlExporter($metadata);
     *     $exporter->setOutputDir(__DIR__ . '/xml');
     *     $exporter->export();
     *
     * @param string $dir
     *
     * @return void
     */
    public function setOutputDir($dir)
    {
        $this->outputDir = $dir;
    }

    /**
     * Exports each ClassMetadata instance to a single Doctrine Mapping file
     * named after the entity.
     *
     * @return void
     *
     * @throws \Doctrine\ORM\Tools\Export\ExportException
     */
    public function export()
    {
        if ( ! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0775, true);
        }

        foreach ($this->metadata as $metadata) {
            // In case output is returned, write it to a file, skip otherwise
            if ($output = $this->exportClassMetadata($metadata)) {
                $path = $this->generateOutputPath($metadata);
                $dir = dirname($path);
                if ( ! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (file_exists($path) && !$this->overwriteExistingFiles) {
                    throw ExportException::attemptOverwriteExistingFile($path);
                }
                file_put_contents($path, $output);
                chmod($path, 0664);
            }
        }
    }

    /**
     * Generates the path to write the class for the given ClassMetadata instance.
     *
     * @param ClassMetadata $metadata
     *
     * @return string
     */
    protected function generateOutputPath(ClassMetadata $metadata)
    {
        return $this->outputDir . '/' . str_replace('\\', '.', $metadata->getClassName()) . $this->extension;
    }

    /**
     * Sets the directory to output the mapping files to.
     *
     *     [php]
     *     $exporter = new XmlExporter($metadata, __DIR__ . '/xml');
     *     $exporter->setExtension('.xml');
     *     $exporter->export();
     *
     * @param string $extension
     *
     * @return void
     */
    public function setExtension($extension)
    {
        $this->extension = $extension;
    }
}
