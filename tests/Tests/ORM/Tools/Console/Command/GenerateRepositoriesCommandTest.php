<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\GenerateRepositoriesCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Tests\Models\DDC3231\DDC3231EntityRepository;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;

use function arsort;
use function assert;
use function class_exists;
use function mkdir;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

class GenerateRepositoriesCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    /** @var Application */
    private $application;

    /** @var string */
    private $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('doctrine_');

        mkdir($this->path);

        $metadataDriver = $this->_em->getConfiguration()->getMetadataDriverImpl();
        $metadataDriver->addPaths([__DIR__ . '/../../../../Models/DDC3231/']);

        $this->application = new Application();
        self::addCommandToApplication($this->application, new GenerateRepositoriesCommand(new SingleManagerProvider($this->_em)));
    }

    public function tearDown(): void
    {
        $dirs = [];

        $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path));
        foreach ($ri as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->isFile()) {
                unlink($file->getPathname());
            } elseif ($file->getBasename() === '.') {
                $dirs[] = $file->getRealPath();
            }
        }

        arsort($dirs);

        foreach ($dirs as $dir) {
            rmdir($dir);
        }

        parent::tearDown();
    }

    public function testGenerateRepositories(): void
    {
        $this->generateRepositories('DDC3231User1');

        $cname = 'Doctrine\Tests\Models\DDC3231\DDC3231User1Repository';
        $fname = str_replace('\\', DIRECTORY_SEPARATOR, $cname) . '.php';

        self::assertFileExists($this->path . DIRECTORY_SEPARATOR . $fname);
        self::assertFileExists($this->path . DIRECTORY_SEPARATOR . 'DDC3231User1NoNamespaceRepository.php');

        require $this->path . DIRECTORY_SEPARATOR . $fname;
        require $this->path . DIRECTORY_SEPARATOR . 'DDC3231User1NoNamespaceRepository.php';

        self::assertTrue(class_exists($cname));
        self::assertTrue(class_exists('DDC3231User1NoNamespaceRepository'));

        $repo1 = new ReflectionClass($cname);
        $repo2 = new ReflectionClass('DDC3231User1NoNamespaceRepository');

        self::assertSame(EntityRepository::class, $repo1->getParentClass()->getName());
        self::assertSame(EntityRepository::class, $repo2->getParentClass()->getName());
    }

    public function testGenerateRepositoriesCustomDefaultRepository(): void
    {
        $this->generateRepositories('DDC3231User2', DDC3231EntityRepository::class);

        $cname = 'Doctrine\Tests\Models\DDC3231\DDC3231User2Repository';
        $fname = str_replace('\\', DIRECTORY_SEPARATOR, $cname) . '.php';

        self::assertFileExists($this->path . DIRECTORY_SEPARATOR . $fname);
        self::assertFileExists($this->path . DIRECTORY_SEPARATOR . 'DDC3231User2NoNamespaceRepository.php');

        require $this->path . DIRECTORY_SEPARATOR . $fname;
        require $this->path . DIRECTORY_SEPARATOR . 'DDC3231User2NoNamespaceRepository.php';

        self::assertTrue(class_exists($cname));
        self::assertTrue(class_exists('DDC3231User2NoNamespaceRepository'));

        $repo1 = new ReflectionClass($cname);
        $repo2 = new ReflectionClass('DDC3231User2NoNamespaceRepository');

        self::assertSame(DDC3231EntityRepository::class, $repo1->getParentClass()->getName());
        self::assertSame(DDC3231EntityRepository::class, $repo2->getParentClass()->getName());
    }

    private function generateRepositories(string $filter, ?string $defaultRepository = null): void
    {
        if ($defaultRepository) {
            $this->_em->getConfiguration()->setDefaultRepositoryClassName($defaultRepository);
        }

        $command = $this->application->find('orm:generate-repositories');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'   => $command->getName(),
                'dest-path' => $this->path,
                '--filter'  => $filter,
            ]
        );
    }

    public function testNoMetadataClassesToProcess(): void
    {
        $configuration   = $this->createMock(Configuration::class);
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $em              = $this->createMock(EntityManagerInterface::class);

        $configuration->method('getDefaultRepositoryClassName')
                      ->willReturn('fooRepository');

        $metadataFactory->method('getAllMetadata')
                        ->willReturn([]);

        $em->method('getMetadataFactory')
           ->willReturn($metadataFactory);

        $em->method('getConfiguration')
           ->willReturn($configuration);

        $application = new Application();
        $application->setHelperSet(new HelperSet(['em' => new EntityManagerHelper($em)]));
        self::addCommandToApplication($application, new GenerateRepositoriesCommand());

        $command = $application->find('orm:generate-repositories');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'   => $command->getName(),
                'dest-path' => $this->path,
            ]
        );

        self::assertUnwrappedDisplayContainsString('Command orm:generate-repositories is deprecated and will be removed in Doctrine ORM 3.0.', $tester);
        self::assertUnwrappedDisplayContainsString('[OK] No Metadata Classes to process.', $tester);
    }
}
