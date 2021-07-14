<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Export;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Tools\Export\ClassMetadataExporter;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\PHPDriver;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\OrmTestCase;
use Symfony\Component\Yaml\Parser;

use function count;
use function current;
use function file_exists;
use function file_get_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function rmdir;
use function rtrim;
use function simplexml_load_file;
use function str_replace;
use function unlink;

/**
 * Test case for ClassMetadataExporter
 *
 * @link        http://www.phpdoctrine.org
 */
abstract class AbstractClassMetadataExporterTest extends OrmTestCase
{
    /** @var string|null */
    protected $extension;

    abstract protected function getType(): string;

    protected function createEntityManager($metadataDriver): EntityManagerMock
    {
        $driverMock = new DriverMock();
        $config     = new Configuration();
        $config->setProxyDir(__DIR__ . '/../../Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $eventManager = new EventManager();
        $conn         = new ConnectionMock([], $driverMock, $config, $eventManager);
        $config->setMetadataDriverImpl($metadataDriver);

        return EntityManagerMock::create($conn, $config, $eventManager);
    }

    protected function createMetadataDriver(string $type, string $path): MappingDriver
    {
        $mappingDriver = [
            'php'        => PHPDriver::class,
            'annotation' => AnnotationDriver::class,
            'xml'        => XmlDriver::class,
            'yaml'       => YamlDriver::class,
        ];

        $this->assertArrayHasKey($type, $mappingDriver, "There is no metadata driver for the type '" . $type . "'.");

        $class = $mappingDriver[$type];

        return $type === 'annotation'
            ? $this->createAnnotationDriver([$path])
            : new $class($path);
    }

    protected function createClassMetadataFactory(EntityManagerInterface $em, string $type): ClassMetadataFactory
    {
        $factory = $type === 'annotation'
            ? new ClassMetadataFactory()
            : new DisconnectedClassMetadataFactory();

        $factory->setEntityManager($em);

        return $factory;
    }

    public function testExportDirectoryAndFilesAreCreated(): void
    {
        $this->deleteDirectory(__DIR__ . '/export/' . $this->getType());

        $type           = $this->getType();
        $metadataDriver = $this->createMetadataDriver($type, __DIR__ . '/' . $type);
        $em             = $this->createEntityManager($metadataDriver);
        $cmf            = $this->createClassMetadataFactory($em, $type);
        $metadata       = $cmf->getAllMetadata();

        $metadata[0]->name = ExportedUser::class;

        $this->assertEquals(ExportedUser::class, $metadata[0]->name);

        $type     = $this->getType();
        $cme      = new ClassMetadataExporter();
        $exporter = $cme->getExporter($type, __DIR__ . '/export/' . $type);

        if ($type === 'annotation') {
            $entityGenerator = new EntityGenerator();

            $exporter->setEntityGenerator($entityGenerator);
        }

        $this->extension = $exporter->getExtension();

        $exporter->setMetadata($metadata);
        $exporter->export();

        if ($type === 'annotation') {
            $this->assertTrue(file_exists(__DIR__ . '/export/' . $type . '/' . str_replace('\\', '/', ExportedUser::class) . $this->extension));
        } else {
            $this->assertTrue(file_exists(__DIR__ . '/export/' . $type . '/Doctrine.Tests.ORM.Tools.Export.ExportedUser' . $this->extension));
        }
    }

    /**
     * @depends testExportDirectoryAndFilesAreCreated
     */
    public function testExportedMetadataCanBeReadBackIn(): ClassMetadataInfo
    {
        $type = $this->getType();

        $metadataDriver = $this->createMetadataDriver($type, __DIR__ . '/export/' . $type);
        $em             = $this->createEntityManager($metadataDriver);
        $cmf            = $this->createClassMetadataFactory($em, $type);
        $metadata       = $cmf->getAllMetadata();

        $this->assertEquals(1, count($metadata));

        $class = current($metadata);

        $this->assertEquals(ExportedUser::class, $class->name);

        return $class;
    }

    /**
     * @depends testExportedMetadataCanBeReadBackIn
     */
    public function testTableIsExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertEquals('cms_users', $class->table['name']);
        $this->assertEquals(
            ['engine' => 'MyISAM', 'foo' => ['bar' => 'baz']],
            $class->table['options']
        );

        return $class;
    }

    /**
     * @depends testTableIsExported
     */
    public function testTypeIsExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertFalse($class->isMappedSuperclass);

        return $class;
    }

    /**
     * @depends testTypeIsExported
     */
    public function testIdentifierIsExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertEquals(ClassMetadataInfo::GENERATOR_TYPE_IDENTITY, $class->generatorType, 'Generator Type wrong');
        $this->assertEquals(['id'], $class->identifier);
        $this->assertTrue(isset($class->fieldMappings['id']['id']) && $class->fieldMappings['id']['id'] === true);

        return $class;
    }

    /**
     * @depends testIdentifierIsExported
     */
    public function testFieldsAreExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue(isset($class->fieldMappings['id']['id']) && $class->fieldMappings['id']['id'] === true);
        $this->assertEquals('id', $class->fieldMappings['id']['fieldName']);
        $this->assertEquals('integer', $class->fieldMappings['id']['type']);
        $this->assertEquals('id', $class->fieldMappings['id']['columnName']);

        $this->assertEquals('name', $class->fieldMappings['name']['fieldName']);
        $this->assertEquals('string', $class->fieldMappings['name']['type']);
        $this->assertEquals(50, $class->fieldMappings['name']['length']);
        $this->assertEquals('name', $class->fieldMappings['name']['columnName']);

        $this->assertEquals('email', $class->fieldMappings['email']['fieldName']);
        $this->assertEquals('string', $class->fieldMappings['email']['type']);
        $this->assertEquals('user_email', $class->fieldMappings['email']['columnName']);
        $this->assertEquals('CHAR(32) NOT NULL', $class->fieldMappings['email']['columnDefinition']);

        $this->assertEquals(true, $class->fieldMappings['age']['options']['unsigned']);

        return $class;
    }

    /**
     * @depends testExportDirectoryAndFilesAreCreated
     */
    public function testFieldsAreProperlySerialized(): void
    {
        $type = $this->getType();

        if ($type === 'xml') {
            $xml = simplexml_load_file(__DIR__ . '/export/' . $type . '/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.xml');

            $xml->registerXPathNamespace('d', 'http://doctrine-project.org/schemas/orm/doctrine-mapping');
            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:field[@name='name' and @type='string' and @nullable='true']");
            $this->assertEquals(1, count($nodes));

            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:field[@name='name' and @type='string' and @unique='true']");
            $this->assertEquals(1, count($nodes));
        } else {
            $this->markTestSkipped('Test not available for ' . $type . ' driver');
        }
    }

    /**
     * @depends testFieldsAreExported
     */
    public function testOneToOneAssociationsAreExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue(isset($class->associationMappings['address']));
        $this->assertEquals(Address::class, $class->associationMappings['address']['targetEntity']);
        $this->assertEquals('address_id', $class->associationMappings['address']['joinColumns'][0]['name']);
        $this->assertEquals('id', $class->associationMappings['address']['joinColumns'][0]['referencedColumnName']);
        $this->assertEquals('CASCADE', $class->associationMappings['address']['joinColumns'][0]['onDelete']);

        $this->assertTrue($class->associationMappings['address']['isCascadeRemove']);
        $this->assertTrue($class->associationMappings['address']['isCascadePersist']);
        $this->assertFalse($class->associationMappings['address']['isCascadeRefresh']);
        $this->assertFalse($class->associationMappings['address']['isCascadeMerge']);
        $this->assertFalse($class->associationMappings['address']['isCascadeDetach']);
        $this->assertTrue($class->associationMappings['address']['orphanRemoval']);
        $this->assertEquals(ClassMetadataInfo::FETCH_EAGER, $class->associationMappings['address']['fetch']);

        return $class;
    }

    /**
     * @depends testFieldsAreExported
     */
    public function testManyToOneAssociationsAreExported($class): void
    {
        $this->assertTrue(isset($class->associationMappings['mainGroup']));
        $this->assertEquals(Group::class, $class->associationMappings['mainGroup']['targetEntity']);
    }

    /**
     * @depends testOneToOneAssociationsAreExported
     */
    public function testOneToManyAssociationsAreExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue(isset($class->associationMappings['phonenumbers']));
        $this->assertEquals(Phonenumber::class, $class->associationMappings['phonenumbers']['targetEntity']);
        $this->assertEquals('user', $class->associationMappings['phonenumbers']['mappedBy']);
        $this->assertEquals(['number' => 'ASC'], $class->associationMappings['phonenumbers']['orderBy']);

        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadeRemove']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadePersist']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeRefresh']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadeMerge']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeDetach']);
        $this->assertTrue($class->associationMappings['phonenumbers']['orphanRemoval']);
        $this->assertEquals(ClassMetadataInfo::FETCH_LAZY, $class->associationMappings['phonenumbers']['fetch']);

        return $class;
    }

    /**
     * @depends testOneToManyAssociationsAreExported
     */
    public function testManyToManyAssociationsAreExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue(isset($class->associationMappings['groups']));
        $this->assertEquals(Group::class, $class->associationMappings['groups']['targetEntity']);
        $this->assertEquals('cms_users_groups', $class->associationMappings['groups']['joinTable']['name']);

        $this->assertEquals('user_id', $class->associationMappings['groups']['joinTable']['joinColumns'][0]['name']);
        $this->assertEquals('id', $class->associationMappings['groups']['joinTable']['joinColumns'][0]['referencedColumnName']);

        $this->assertEquals('group_id', $class->associationMappings['groups']['joinTable']['inverseJoinColumns'][0]['name']);
        $this->assertEquals('id', $class->associationMappings['groups']['joinTable']['inverseJoinColumns'][0]['referencedColumnName']);
        $this->assertEquals('INT NULL', $class->associationMappings['groups']['joinTable']['inverseJoinColumns'][0]['columnDefinition']);

        $this->assertTrue($class->associationMappings['groups']['isCascadeRemove']);
        $this->assertTrue($class->associationMappings['groups']['isCascadePersist']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeRefresh']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeMerge']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeDetach']);
        $this->assertEquals(ClassMetadataInfo::FETCH_EXTRA_LAZY, $class->associationMappings['groups']['fetch']);

        return $class;
    }

    /**
     * @depends testManyToManyAssociationsAreExported
     */
    public function testLifecycleCallbacksAreExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue(isset($class->lifecycleCallbacks['prePersist']));
        $this->assertEquals(2, count($class->lifecycleCallbacks['prePersist']));
        $this->assertEquals('doStuffOnPrePersist', $class->lifecycleCallbacks['prePersist'][0]);
        $this->assertEquals('doOtherStuffOnPrePersistToo', $class->lifecycleCallbacks['prePersist'][1]);

        $this->assertTrue(isset($class->lifecycleCallbacks['postPersist']));
        $this->assertEquals(1, count($class->lifecycleCallbacks['postPersist']));
        $this->assertEquals('doStuffOnPostPersist', $class->lifecycleCallbacks['postPersist'][0]);

        return $class;
    }

    /**
     * @depends testLifecycleCallbacksAreExported
     */
    public function testCascadeIsExported(ClassMetadataInfo $class): ClassMetadataInfo
    {
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadePersist']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadeMerge']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadeRemove']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeRefresh']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeDetach']);
        $this->assertTrue($class->associationMappings['phonenumbers']['orphanRemoval']);

        return $class;
    }

    /**
     * @depends testCascadeIsExported
     */
    public function testInversedByIsExported(ClassMetadataInfo $class): void
    {
        $this->assertEquals('user', $class->associationMappings['address']['inversedBy']);
    }

    /**
     * @depends testExportDirectoryAndFilesAreCreated
     */
    public function testCascadeAllCollapsed(): void
    {
        $type = $this->getType();

        if ($type === 'xml') {
            $xml = simplexml_load_file(__DIR__ . '/export/' . $type . '/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.xml');

            $xml->registerXPathNamespace('d', 'http://doctrine-project.org/schemas/orm/doctrine-mapping');
            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:one-to-many[@field='interests']/d:cascade/d:*");
            $this->assertEquals(1, count($nodes));

            $this->assertEquals('cascade-all', $nodes[0]->getName());
        } elseif ($type === 'yaml') {
            $yaml  = new Parser();
            $value = $yaml->parse(file_get_contents(__DIR__ . '/export/' . $type . '/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.yml'));

            $this->assertTrue(isset($value[ExportedUser::class]['oneToMany']['interests']['cascade']));
            $this->assertEquals(1, count($value[ExportedUser::class]['oneToMany']['interests']['cascade']));
            $this->assertEquals('all', $value[ExportedUser::class]['oneToMany']['interests']['cascade'][0]);
        } else {
            $this->markTestSkipped('Test not available for ' . $type . ' driver');
        }
    }

    /**
     * @depends testExportedMetadataCanBeReadBackIn
     */
    public function testEntityListenersAreExported(ClassMetadata $class): void
    {
        $this->assertNotEmpty($class->entityListeners);
        $this->assertCount(2, $class->entityListeners[Events::prePersist]);
        $this->assertCount(2, $class->entityListeners[Events::postPersist]);
        $this->assertEquals(UserListener::class, $class->entityListeners[Events::prePersist][0]['class']);
        $this->assertEquals('customPrePersist', $class->entityListeners[Events::prePersist][0]['method']);
        $this->assertEquals(GroupListener::class, $class->entityListeners[Events::prePersist][1]['class']);
        $this->assertEquals('prePersist', $class->entityListeners[Events::prePersist][1]['method']);
        $this->assertEquals(UserListener::class, $class->entityListeners[Events::postPersist][0]['class']);
        $this->assertEquals('customPostPersist', $class->entityListeners[Events::postPersist][0]['method']);
        $this->assertEquals(AddressListener::class, $class->entityListeners[Events::postPersist][1]['class']);
        $this->assertEquals('customPostPersist', $class->entityListeners[Events::postPersist][1]['method']);
    }

    protected function deleteDirectory(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*');

            if (is_array($files)) {
                foreach ($files as $file) {
                    $this->deleteDirectory($file);
                }
            }

            rmdir($path);
        }
    }
}

class Address
{
}
class Phonenumber
{
}
class Group
{
}
class UserListener
{
    /**
     * @\Doctrine\ORM\Mapping\PrePersist
     */
    public function customPrePersist(): void
    {
    }

    /**
     * @\Doctrine\ORM\Mapping\PostPersist
     */
    public function customPostPersist(): void
    {
    }
}
class GroupListener
{
    /**
     * @\Doctrine\ORM\Mapping\PrePersist
     */
    public function prePersist(): void
    {
    }
}
class AddressListener
{
    /**
     * @\Doctrine\ORM\Mapping\PostPersist
     */
    public function customPostPersist(): void
    {
    }
}
