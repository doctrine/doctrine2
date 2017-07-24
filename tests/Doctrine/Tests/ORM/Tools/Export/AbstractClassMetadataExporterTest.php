<?php

namespace Doctrine\Tests\ORM\Tools\Export;

use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\Mapping\Driver\PHPDriver;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Tools\Export\ClassMetadataExporter;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\OrmTestCase;
use Symfony\Component\Yaml\Parser;

/**
 * Test case for ClassMetadataExporter
 *
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        http://www.phpdoctrine.org
 * @since       2.0
 * @version     $Revision$
 */
abstract class AbstractClassMetadataExporterTest extends OrmTestCase
{
    protected $_extension;

    abstract protected function _getType();

    protected function _createEntityManager($metadataDriver)
    {
        $driverMock = new DriverMock();
        $config = new Configuration();
        $config->setProxyDir(__DIR__ . '/../../Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $eventManager = new EventManager();
        $conn = new ConnectionMock([], $driverMock, $config, $eventManager);
        $config->setMetadataDriverImpl($metadataDriver);

        return EntityManagerMock::create($conn, $config, $eventManager);
    }

    protected function _createMetadataDriver($type, $path)
    {
        $mappingDriver = [
            'php'        => PHPDriver::class,
            'annotation' => AnnotationDriver::class,
            'xml'        => XmlDriver::class,
            'yaml'       => YamlDriver::class,
        ];

        $this->assertArrayHasKey($type, $mappingDriver, "There is no metadata driver for the type '" . $type . "'.");

        $class  = $mappingDriver[$type];
        $driver = ($type === 'annotation')
            ? $this->createAnnotationDriver([$path])
            : new $class($path);

        return $driver;
    }

    protected function _createClassMetadataFactory($em, $type)
    {
        $factory = ($type === 'annotation')
            ? new ClassMetadataFactory()
            : new DisconnectedClassMetadataFactory();

        $factory->setEntityManager($em);

        return $factory;
    }

    public function testExportDirectoryAndFilesAreCreated()
    {
        $this->_deleteDirectory(__DIR__ . '/export/'.$this->_getType());

        $type = $this->_getType();
        $metadataDriver = $this->_createMetadataDriver($type, __DIR__ . '/' . $type);
        $em = $this->_createEntityManager($metadataDriver);
        $cmf = $this->_createClassMetadataFactory($em, $type);
        $metadata = $cmf->getAllMetadata();

        $metadata[0]->name = ExportedUser::class;

        $this->assertEquals(ExportedUser::class, $metadata[0]->name);

        $type = $this->_getType();
        $cme = new ClassMetadataExporter();
        $exporter = $cme->getExporter($type, __DIR__ . '/export/' . $type);

        if ($type === 'annotation') {
            $entityGenerator = new EntityGenerator();

            $entityGenerator->setAnnotationPrefix("");
            $exporter->setEntityGenerator($entityGenerator);
        }

        $this->_extension = $exporter->getExtension();

        $exporter->setMetadata($metadata);
        $exporter->export();

        if ($type == 'annotation') {
            $this->assertTrue(file_exists(__DIR__ . '/export/' . $type . '/'.str_replace('\\', '/', ExportedUser::class).$this->_extension));
        } else {
            $this->assertTrue(file_exists(__DIR__ . '/export/' . $type . '/Doctrine.Tests.ORM.Tools.Export.ExportedUser'.$this->_extension));
        }
    }

    /**
     * @depends testExportDirectoryAndFilesAreCreated
     */
    public function testExportedMetadataCanBeReadBackIn()
    {
        $type = $this->_getType();

        $metadataDriver = $this->_createMetadataDriver($type, __DIR__ . '/export/' . $type);
        $em = $this->_createEntityManager($metadataDriver);
        $cmf = $this->_createClassMetadataFactory($em, $type);
        $metadata = $cmf->getAllMetadata();

        $this->assertEquals(1, count($metadata));

        $class = current($metadata);

        $this->assertEquals(ExportedUser::class, $class->name);

        return $class;
    }

    /**
     * @depends testExportedMetadataCanBeReadBackIn
     * @param ClassMetadataInfo $class
     */
    public function testTableIsExported($class)
    {
        $this->assertEquals('cms_users', $class->table['name']);
        $this->assertEquals(
            ['engine' => 'MyISAM', 'foo' => ['bar' => 'baz']],
            $class->table['options']);

        return $class;
    }

    /**
     * @depends testTableIsExported
     * @param ClassMetadataInfo $class
     */
    public function testTypeIsExported($class)
    {
        $this->assertFalse($class->isMappedSuperclass);

        return $class;
    }

    /**
     * @depends testTypeIsExported
     * @param ClassMetadataInfo $class
     */
    public function testIdentifierIsExported($class)
    {
        $this->assertEquals(ClassMetadataInfo::GENERATOR_TYPE_IDENTITY, $class->generatorType, "Generator Type wrong");
        $this->assertEquals(['id'], $class->identifier);
        $this->assertTrue(isset($class->fieldMappings['id']['id']) && $class->fieldMappings['id']['id'] === true);

        return $class;
    }

    /**
     * @depends testIdentifierIsExported
     * @param ClassMetadataInfo $class
     */
    public function testFieldsAreExported($class)
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
    public function testFieldsAreProperlySerialized()
    {
        $type = $this->_getType();

        if ($type == 'xml') {
            $xml = simplexml_load_file(__DIR__ . '/export/'.$type.'/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.xml');

            $xml->registerXPathNamespace("d", "http://doctrine-project.org/schemas/orm/doctrine-mapping");
            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:field[@name='name' and @type='string' and @nullable='true']");
            $this->assertEquals(1, count($nodes));

            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:field[@name='name' and @type='string' and @unique='true']");
            $this->assertEquals(1, count($nodes));
        } else {
            $this->markTestSkipped('Test not available for '.$type.' driver');
        }
    }

    /**
     * @depends testFieldsAreExported
     * @param ClassMetadataInfo $class
     */
    public function testOneToOneAssociationsAreExported($class)
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
    public function testManyToOneAssociationsAreExported($class)
    {
        $this->assertTrue(isset($class->associationMappings['mainGroup']));
        $this->assertEquals(Group::class, $class->associationMappings['mainGroup']['targetEntity']);
    }

    /**
     * @depends testOneToOneAssociationsAreExported
     * @param ClassMetadataInfo $class
     */
    public function testOneToManyAssociationsAreExported($class)
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
     * @param ClassMetadataInfo $metadata
     */
    public function testManyToManyAssociationsAreExported($class)
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
     * @param ClassMetadataInfo $class
     */
    public function testLifecycleCallbacksAreExported($class)
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
     * @param ClassMetadataInfo $class
     */
    public function testCascadeIsExported($class)
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
     * @param ClassMetadataInfo $class
     */
    public function testInversedByIsExported($class)
    {
        $this->assertEquals('user', $class->associationMappings['address']['inversedBy']);
    }
	/**
     * @depends testExportDirectoryAndFilesAreCreated
     */
    public function testCascadeAllCollapsed()
    {
        $type = $this->_getType();

        if ($type == 'xml') {
            $xml = simplexml_load_file(__DIR__ . '/export/'.$type.'/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.xml');

            $xml->registerXPathNamespace("d", "http://doctrine-project.org/schemas/orm/doctrine-mapping");
            $nodes = $xml->xpath("/d:doctrine-mapping/d:entity/d:one-to-many[@field='interests']/d:cascade/d:*");
            $this->assertEquals(1, count($nodes));

            $this->assertEquals('cascade-all', $nodes[0]->getName());
        } else if ($type == 'yaml') {
            $yaml = new Parser();
            $value = $yaml->parse(file_get_contents(__DIR__ . '/export/'.$type.'/Doctrine.Tests.ORM.Tools.Export.ExportedUser.dcm.yml'));

            $this->assertTrue(isset($value[ExportedUser::class]['oneToMany']['interests']['cascade']));
            $this->assertEquals(1, count($value[ExportedUser::class]['oneToMany']['interests']['cascade']));
            $this->assertEquals('all', $value[ExportedUser::class]['oneToMany']['interests']['cascade'][0]);
        } else {
            $this->markTestSkipped('Test not available for '.$type.' driver');
        }
    }

    public function __destruct()
    {
#        $this->_deleteDirectory(__DIR__ . '/export/'.$this->_getType());
    }

    protected function _deleteDirectory($path)
    {
        if (is_file($path)) {
            return unlink($path);
        } else if (is_dir($path)) {
            $files = glob(rtrim($path,'/').'/*');

            if (is_array($files)) {
                foreach ($files as $file){
                    $this->_deleteDirectory($file);
                }
            }

            return rmdir($path);
        }
    }

    /**
     * Returns the metadata generated for the issue DCC-2632
     *
     *
     * @access
     * @return array
     */
    protected function getMetadatasDCC2632Nonullable() {
        $metadata = array();
        $metadata['Ddc2059User'] = new ClassMetadataInfo('Ddc2059User');
        $metadata['Ddc2059User']->table['name'] = 'Ddc2059User';
        $metadata['Ddc2059User']->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);

        $metadata['Ddc2059Project'] = new ClassMetadataInfo('Ddc2059Project');
        $metadata['Ddc2059Project']->table['name'] = 'Ddc2059Project';
        $metadata['Ddc2059Project']->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);

        $metadata['Ddc2059Project']->mapManyToOne(
            ['fieldName' => 'user_id', 'targetEntity' => 'Ddc2059User', 'mappedBy' => 'id',
                'joinColumns' =>
                    [
                        0 =>
                            [
                                'name' => 'user_id',
                                'referencedColumnName' => 'id',
                                'nullable' => false,
                            ],
                    ]
            ]
        );
        return $metadata;
    }
    /**
     * Returns the metadata generated for the issue DCC-2632
     *
     *
     * @access
     * @return array
     */
    protected function getMetadatasDCC2632Nullable() {
        $metadata = array();
        $metadata['Ddc2059User'] = new ClassMetadataInfo('Ddc2059User');
        $metadata['Ddc2059User']->table['name'] = 'Ddc2059User';
        $metadata['Ddc2059User']->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);

        $metadata['Ddc2059Project'] = new ClassMetadataInfo('Ddc2059Project');
        $metadata['Ddc2059Project']->table['name'] = 'Ddc2059Project';
        $metadata['Ddc2059Project']->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);

        $metadata['Ddc2059Project']->mapManyToOne(
            ['fieldName' => 'user_id', 'targetEntity' => 'Ddc2059User', 'mappedBy' => 'id',
                'joinColumns' =>
                    [
                        0 =>
                            [
                                'name' => 'user_id',
                                'referencedColumnName' => 'id'
                            ],
                    ]
            ]
        );
        return $metadata;
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
