<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Cache;

use Doctrine\ORM\Mapping\ReadonlyClassMetadataBag;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\VarExporter\VarExporter;

/**
 * Stores a complete {@see ReadonlyClassMetadataBag} snapshot as a single, opcache-friendly PHP file in the
 * given directory.
 *
 * @todo For now, no cache revalidation is implemented.
 */
final class FileClassMetadatasCache implements ClassMetadatasCache
{
    private const FILENAME = 'classmetadatas.compiled.php';

    private readonly ConfigCache $configCache;

    public function __construct(string $directory)
    {
        // @todo "debug: false" skips cache freshness checks
        $this->configCache = new ConfigCache($directory . '/' . self::FILENAME, debug: false);
    }

    public function load(): ReadonlyClassMetadataBag|null
    {
        if (! $this->configCache->isFresh()) {
            return null;
        }

        return require $this->configCache->getPath();
    }

    public function save(ReadonlyClassMetadataBag $classMetadatas): void
    {
        $this->configCache->write("<?php\n\nreturn " . VarExporter::export($classMetadatas) . ";\n");
    }
}
