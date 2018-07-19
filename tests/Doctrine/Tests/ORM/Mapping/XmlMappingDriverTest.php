<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Tests\Models\DDC117\DDC117Translation;
use Doctrine\Tests\Models\DDC3293\DDC3293User;
use Doctrine\Tests\Models\DDC3293\DDC3293UserPrefixed;
use Doctrine\Tests\Models\DDC889\DDC889Class;
use Doctrine\Tests\Models\Generic\SerializationModel;
use Doctrine\Tests\Models\ValueObjects\Name;
use Doctrine\Tests\Models\ValueObjects\Person;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;
use function array_filter;
use function array_map;
use function glob;
use function in_array;
use function iterator_to_array;
use function pathinfo;

class XmlMappingDriverTest extends AbstractMappingDriverTest
{
    protected function loadDriver()
    {
        return new XmlDriver(__DIR__ . DIRECTORY_SEPARATOR . 'xml');
    }

    public function testClassTableInheritanceDiscriminatorMap() : void
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata(CTI::class, $this->metadataBuildingContext);

        $mappingDriver->loadMetadataForClass(CTI::class, $class, $this->metadataBuildingContext);

        $expectedMap = [
            'foo' => CTIFoo::class,
            'bar' => CTIBar::class,
            'baz' => CTIBaz::class,
        ];

        self::assertCount(3, $class->discriminatorMap);
        self::assertEquals($expectedMap, $class->discriminatorMap);
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Entity association field "Doctrine\Tests\ORM\Mapping\XMLSLC#foo" not configured as part of the second-level cache.
     */
    public function testFailingSecondLevelCacheAssociation() : void
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata(XMLSLC::class, $this->metadataBuildingContext);

        $mappingDriver->loadMetadataForClass(XMLSLC::class, $class, $this->metadataBuildingContext);
    }

    public function testIdentifierWithAssociationKey() : void
    {
        $driver  = $this->loadDriver();
        $em      = $this->getTestEntityManager();
        $factory = new ClassMetadataFactory();

        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $factory->setEntityManager($em);

        $class = $factory->getMetadataFor(DDC117Translation::class);

        self::assertEquals(['language', 'article'], $class->identifier);
        self::assertArrayHasKey('article', iterator_to_array($class->getDeclaredPropertiesIterator()));

        $association = $class->getProperty('article');

        self::assertTrue($association->isPrimaryKey());
    }

    /**
     * @group embedded
     */
    public function testEmbeddableMapping() : void
    {
        $class = $this->createClassMetadata(Name::class);

        self::assertTrue($class->isEmbeddedClass);
    }

    /**
     * @group embedded
     * @group DDC-3293
     * @group DDC-3477
     * @group DDC-1238
     */
    public function testEmbeddedMappingsWithUseColumnPrefix() : void
    {
        $factory = new ClassMetadataFactory();
        $em      = $this->getTestEntityManager();

        $em->getConfiguration()->setMetadataDriverImpl($this->loadDriver());
        $factory->setEntityManager($em);

        self::assertEquals(
            '__prefix__',
            $factory->getMetadataFor(DDC3293UserPrefixed::class)
                ->embeddedClasses['address']['columnPrefix']
        );
    }

    /**
     * @group embedded
     * @group DDC-3293
     * @group DDC-3477
     * @group DDC-1238
     */
    public function testEmbeddedMappingsWithFalseUseColumnPrefix() : void
    {
        $factory = new ClassMetadataFactory();
        $em      = $this->getTestEntityManager();

        $em->getConfiguration()->setMetadataDriverImpl($this->loadDriver());
        $factory->setEntityManager($em);

        self::assertFalse(
            $factory->getMetadataFor(DDC3293User::class)
                ->embeddedClasses['address']['columnPrefix']
        );
    }

    /**
     * @group embedded
     */
    public function testEmbeddedMapping() : void
    {
        $class = $this->createClassMetadata(Person::class);

        self::assertEquals(
            [
                'name' => [
                    'class'          => Name::class,
                    'columnPrefix'   => 'nm_',
                    'declaredField'  => null,
                    'originalField'  => null,
                    'declaringClass' => $class,
                ],
            ],
            $class->embeddedClasses
        );
    }

    /**
     * @group DDC-1468
     *
     * @expectedException \Doctrine\Common\Persistence\Mapping\MappingException
     * @expectedExceptionMessage Invalid mapping file 'Doctrine.Tests.Models.Generic.SerializationModel.dcm.xml' for class 'Doctrine\Tests\Models\Generic\SerializationModel'.
     */
    public function testInvalidMappingFileException() : void
    {
        $this->createClassMetadata(SerializationModel::class);
    }

    /**
     * @param string $xmlMappingFile
     * @dataProvider dataValidSchema
     * @group DDC-2429
     */
    public function testValidateXmlSchema($xmlMappingFile) : void
    {
        $xsdSchemaFile = __DIR__ . '/../../../../../doctrine-mapping.xsd';
        $dom           = new \DOMDocument('UTF-8');

        $dom->load($xmlMappingFile);

        self::assertTrue($dom->schemaValidate($xsdSchemaFile));
    }

    public static function dataValidSchema()
    {
        $list    = glob(__DIR__ . '/xml/*.xml');
        $invalid = ['Doctrine.Tests.Models.DDC889.DDC889Class.dcm'];

        $list = array_filter($list, function ($item) use ($invalid) {
            return ! in_array(pathinfo($item, PATHINFO_FILENAME), $invalid, true);
        });

        return array_map(function ($item) {
            return [$item];
        }, $list);
    }

    /**
     * @group DDC-889
     * @expectedException \Doctrine\Common\Persistence\Mapping\MappingException
     * @expectedExceptionMessage Invalid mapping file 'Doctrine.Tests.Models.DDC889.DDC889Class.dcm.xml' for class 'Doctrine\Tests\Models\DDC889\DDC889Class'.
     */
    public function testinvalidEntityOrMappedSuperClassShouldMentionParentClasses() : void
    {
        $this->createClassMetadata(DDC889Class::class);
    }
}

class CTI
{
    public $id;
}

class CTIFoo extends CTI
{
}
class CTIBar extends CTI
{
}
class CTIBaz extends CTI
{
}

class XMLSLC
{
    public $foo;
}
class XMLSLCFoo
{
    public $id;
}
