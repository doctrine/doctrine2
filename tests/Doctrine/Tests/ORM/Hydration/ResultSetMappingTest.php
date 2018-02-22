<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataBuildingContext;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\OneToOneAssociationMetadata;
use Doctrine\ORM\Mapping\TableMetadata;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Legacy\LegacyUser;
use Doctrine\Tests\Models\Legacy\LegacyUserReference;

/**
 * Description of ResultSetMappingTest
 *
 * @author robo
 */
class ResultSetMappingTest extends \Doctrine\Tests\OrmTestCase
{
    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $em;

    /**
     * @var ResultSetMapping
     */
    private $rsm;

    /**
     * @var ClassMetadataBuildingContext
     */
    private $metadataBuildingContext;

    protected function setUp()
    {
        parent::setUp();

        $this->metadataBuildingContext = new ClassMetadataBuildingContext(
            $this->createMock(ClassMetadataFactory::class),
            new RuntimeReflectionService()
        );

        $this->em  = $this->getTestEntityManager();
        $this->rsm = new ResultSetMapping;
    }

    /**
     * For SQL: SELECT id, status, username, name FROM cms_users
     */
    public function testBasicResultSetMapping()
    {
        $this->rsm->addEntityResult(
            CmsUser::class,
            'u'
        );
        $this->rsm->addFieldResult('u', 'id', 'id');
        $this->rsm->addFieldResult('u', 'status', 'status');
        $this->rsm->addFieldResult('u', 'username', 'username');
        $this->rsm->addFieldResult('u', 'name', 'name');

        self::assertFalse($this->rsm->isScalarResult('id'));
        self::assertFalse($this->rsm->isScalarResult('status'));
        self::assertFalse($this->rsm->isScalarResult('username'));
        self::assertFalse($this->rsm->isScalarResult('name'));

        self::assertEquals($this->rsm->getClassName('u'), CmsUser::class);
        $class = $this->rsm->getDeclaringClass('id');
        self::assertEquals($class, CmsUser::class);

        self::assertEquals('u', $this->rsm->getEntityAlias('id'));
        self::assertEquals('u', $this->rsm->getEntityAlias('status'));
        self::assertEquals('u', $this->rsm->getEntityAlias('username'));
        self::assertEquals('u', $this->rsm->getEntityAlias('name'));

        self::assertEquals('id', $this->rsm->getFieldName('id'));
        self::assertEquals('status', $this->rsm->getFieldName('status'));
        self::assertEquals('username', $this->rsm->getFieldName('username'));
        self::assertEquals('name', $this->rsm->getFieldName('name'));
    }

    /**
     * @group DDC-1057
     *
     * Fluent interface test, not a real result set mapping
     */
    public function testFluentInterface()
    {
        $rms = $this->rsm;

        $this->rsm->addEntityResult(CmsUser::class, 'u');
        $this->rsm->addJoinedEntityResult(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
        $this->rsm->addFieldResult('u', 'id', 'id');
        $this->rsm->addFieldResult('u', 'name', 'name');
        $this->rsm->setDiscriminatorColumn('name', 'name');
        $this->rsm->addIndexByColumn('id', 'id');
        $this->rsm->addIndexBy('username', 'username');
        $this->rsm->addIndexByScalar('sclr0');
        $this->rsm->addScalarResult('sclr0', 'numPhones', Type::getType('integer'));
        $this->rsm->addMetaResult('a', 'user_id', 'user_id', false, Type::getType('integer'));

        self::assertTrue($rms->hasIndexBy('id'));
        self::assertTrue($rms->isFieldResult('id'));
        self::assertTrue($rms->isFieldResult('name'));
        self::assertTrue($rms->isScalarResult('sclr0'));
        self::assertTrue($rms->isRelation('p'));
        self::assertTrue($rms->hasParentAlias('p'));
        self::assertTrue($rms->isMixedResult());
    }

    /**
     * @group DDC-1663
     */
    public function testAddNamedNativeQueryResultSetMapping()
    {
        $cm = new ClassMetadata(CmsUser::class, $this->metadataBuildingContext);
        $cm->setTable(new TableMetadata("cms_users"));

        $joinColumn = new JoinColumnMetadata();
        $joinColumn->setReferencedColumnName('id');
        $joinColumn->setNullable(true);

        $association = new OneToOneAssociationMetadata('email');
        $association->setTargetEntity(CmsEmail::class);
        $association->setInversedBy('user');
        $association->setCascade(['persist']);
        $association->addJoinColumn($joinColumn);

        $cm->addProperty($association);

        $cm->addNamedNativeQuery(
            'find-all',
            'SELECT u.id AS user_id, e.id AS email_id, u.name, e.email, u.id + e.id AS scalarColumn FROM cms_users u INNER JOIN cms_emails e ON e.id = u.email_id',
            [
                'resultSetMapping' => 'find-all',
            ]
        );

        $cm->addSqlResultSetMapping(
            [
                'name'      => 'find-all',
                'entities'  => [
                    [
                        'entityClass'   => '__CLASS__',
                        'fields'        => [
                            [
                                'name'  => 'id',
                                'column'=> 'user_id'
                            ],
                            [
                                'name'  => 'name',
                                'column'=> 'name'
                            ]
                        ]
                    ],
                    [
                        'entityClass'   => CmsEmail::class,
                        'fields'        => [
                            [
                                'name'  => 'id',
                                'column'=> 'email_id'
                            ],
                            [
                                'name'  => 'email',
                                'column'=> 'email'
                            ]
                        ]
                    ]
                ],
                'columns'   => [
                    [
                        'name' => 'scalarColumn'
                    ]
                ]
            ]
        );

        $queryMapping = $cm->getNamedNativeQuery('find-all');

        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);
        $rsm->addNamedNativeQueryMapping($cm, $queryMapping);

        self::assertEquals('scalarColumn', $rsm->getScalarAlias('scalarColumn'));

        self::assertEquals('e0', $rsm->getEntityAlias('user_id'));
        self::assertEquals('e0', $rsm->getEntityAlias('name'));
        self::assertEquals(CmsUser::class, $rsm->getClassName('e0'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('name'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('user_id'));


        self::assertEquals('e1', $rsm->getEntityAlias('email_id'));
        self::assertEquals('e1', $rsm->getEntityAlias('email'));
        self::assertEquals(CmsEmail::class, $rsm->getClassName('e1'));
        self::assertEquals(CmsEmail::class, $rsm->getDeclaringClass('email'));
        self::assertEquals(CmsEmail::class, $rsm->getDeclaringClass('email_id'));
    }

    /**
     * @group DDC-1663
     */
    public function testAddNamedNativeQueryResultSetMappingWithoutFields()
    {
        $cm = new ClassMetadata(CmsUser::class, $this->metadataBuildingContext);
        $cm->setTable(new TableMetadata("cms_users"));

        $cm->addNamedNativeQuery(
            'find-all',
            'SELECT u.id AS user_id, e.id AS email_id, u.name, e.email, u.id + e.id AS scalarColumn FROM cms_users u INNER JOIN cms_emails e ON e.id = u.email_id',
            [
                'resultSetMapping' => 'find-all',
            ]
        );

        $cm->addSqlResultSetMapping(
            [
            'name'      => 'find-all',
            'entities'  => [
                [
                    'entityClass'   => '__CLASS__',
                ]
            ],
            'columns'   => [
                [
                    'name' => 'scalarColumn'
                ]
            ]
            ]
        );

        $queryMapping = $cm->getNamedNativeQuery('find-all');
        $rsm          = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);

        $rsm->addNamedNativeQueryMapping($cm, $queryMapping);

        self::assertEquals('scalarColumn', $rsm->getScalarAlias('scalarColumn'));
        self::assertEquals('e0', $rsm->getEntityAlias('id'));
        self::assertEquals('e0', $rsm->getEntityAlias('name'));
        self::assertEquals('e0', $rsm->getEntityAlias('status'));
        self::assertEquals('e0', $rsm->getEntityAlias('username'));
        self::assertEquals(CmsUser::class, $rsm->getClassName('e0'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('id'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('name'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('status'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('username'));
    }

    /**
     * @group DDC-1663
     */
    public function testAddNamedNativeQueryResultClass()
    {
        $cm = $this->em->getClassMetadata(CmsUser::class);

        $cm->addNamedNativeQuery(
            'find-all',
            'SELECT * FROM cms_users',
            [
                'resultClass' => '__CLASS__',
            ]
        );

        $queryMapping = $cm->getNamedNativeQuery('find-all');
        $rsm          = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);

        $rsm->addNamedNativeQueryMapping($cm, $queryMapping);

        self::assertEquals('e0', $rsm->getEntityAlias('id'));
        self::assertEquals('e0', $rsm->getEntityAlias('name'));
        self::assertEquals('e0', $rsm->getEntityAlias('status'));
        self::assertEquals('e0', $rsm->getEntityAlias('username'));
        self::assertEquals(CmsUser::class, $rsm->getClassName('e0'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('id'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('name'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('status'));
        self::assertEquals(CmsUser::class, $rsm->getDeclaringClass('username'));
    }
    /**
     * @group DDC-117
     */
    public function testIndexByMetadataColumn()
    {
        $this->rsm->addEntityResult(LegacyUser::class, 'u');
        $this->rsm->addJoinedEntityResult(LegacyUserReference::class, 'lu', 'u', '_references');
        $this->rsm->addMetaResult('lu', '_source',  '_source', true, Type::getType('integer'));
        $this->rsm->addMetaResult('lu', '_target',  '_target', true, Type::getType('integer'));
        $this->rsm->addIndexBy('lu', '_source');

        self::assertTrue($this->rsm->hasIndexBy('lu'));
    }

    public function testNewObjectNestedArgumentsDeepestLeavesShouldComeFirst()
    {
        $this->rsm->addNewObjectAsArgument('objALevel2', 'objALevel1', 0);
        $this->rsm->addNewObjectAsArgument('objALevel3', 'objALevel2', 1);
        $this->rsm->addNewObjectAsArgument('objBLevel3', 'objBLevel2', 0);
        $this->rsm->addNewObjectAsArgument('objBLevel2', 'objBLevel1', 1);

        $expectedArgumentMapping = [
            'objALevel3' => ['ownerIndex' => 'objALevel2', 'argIndex' => 1],
            'objALevel2' => ['ownerIndex' => 'objALevel1', 'argIndex' => 0],
            'objBLevel3' => ['ownerIndex' => 'objBLevel2', 'argIndex' => 0],
            'objBLevel2' => ['ownerIndex' => 'objBLevel1', 'argIndex' => 1],
        ];

        $this->assertEquals($expectedArgumentMapping, $this->rsm->nestedNewObjectArguments);
    }
}
