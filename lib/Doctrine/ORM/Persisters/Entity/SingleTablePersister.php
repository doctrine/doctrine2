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

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Utility\PersisterHelper;

/**
 * Persister for entities that participate in a hierarchy mapped with the
 * SINGLE_TABLE strategy.
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Alexander <iam.asm89@gmail.com>
 * @since 2.0
 * @link http://martinfowler.com/eaaCatalog/singleTableInheritance.html
 */
class SingleTablePersister extends AbstractEntityInheritancePersister
{
    /**
     * {@inheritdoc}
     */
    protected function getSelectColumnsSQL()
    {
        if ($this->currentPersisterContext->selectColumnListSql !== null) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $columnList[] = parent::getSelectColumnsSQL();

        $rootClass  = $this->em->getClassMetadata($this->class->getRootClassName());
        $tableAlias = $this->getSQLTableAlias($rootClass->getTableName());

         // Append discriminator column
        $discrColumn      = $this->class->discriminatorColumn;
        $discrColumnName  = $discrColumn->getColumnName();
        $discrColumnType  = $discrColumn->getType();
        $resultColumnName = $this->platform->getSQLResultCasing($discrColumnName);
        $quotedColumnName = $this->platform->quoteIdentifier($discrColumn->getColumnName());

        $this->currentPersisterContext->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->currentPersisterContext->rsm->addMetaResult('r', $resultColumnName, $discrColumnName, false, $discrColumnType);

        $columnList[] = $discrColumnType->convertToDatabaseValueSQL($tableAlias . '.' . $quotedColumnName, $this->platform);

        // Append subclass columns
        foreach ($this->class->getSubClasses() as $subClassName) {
            $subClass = $this->em->getClassMetadata($subClassName);

            // Subclass columns
            foreach ($subClass->getProperties() as $fieldName => $property) {
                if ($subClass->isInheritedProperty($fieldName)) {
                    continue;
                }

                switch (true) {
                    case ($property instanceof FieldMetadata):
                        $columnList[] = $this->getSelectColumnSQL($fieldName, $subClass);
                        break;

                    case ($property instanceof ToOneAssociationMetadata):
                        $targetClass = $this->em->getClassMetadata($property->getTargetEntity());

                        foreach ($property->getJoinColumns() as $joinColumn) {
                            if (! $joinColumn->getType()) {
                                $joinColumn->setType(
                                    PersisterHelper::getTypeOfColumn($joinColumn->getReferencedColumnName(), $targetClass, $this->em)
                                );
                            }

                            $columnList[] = $this->getSelectJoinColumnSQL($joinColumn);
                        }

                        break;
                }
            }
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * {@inheritdoc}
     */
    protected function getInsertColumnList()
    {
        $columns = parent::getInsertColumnList();

        // Add discriminator column to the INSERT SQL
        $discrColumn     = $this->class->discriminatorColumn;
        $discrColumnName = $discrColumn->getColumnName();

        $columns[] = $discrColumnName;

        $this->columns[$discrColumnName] = $discrColumn;

        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSQLTableAlias($tableName, $assocName = '')
    {
        return parent::getSQLTableAlias($this->class->getTableName(), $assocName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSelectConditionSQL(array $criteria, AssociationMetadata $association = null)
    {
        $conditionSql = parent::getSelectConditionSQL($criteria, $association);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    /**
     * {@inheritdoc}
     */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria)
    {
        $conditionSql = parent::getSelectConditionCriteriaSQL($criteria);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    /**
     * @return string
     */
    protected function getSelectConditionDiscriminatorValueSQL()
    {
        $values = [];

        if ($this->class->discriminatorValue !== null) { // discriminators can be 0
            $values[] = $this->conn->quote($this->class->discriminatorValue);
        }

        $discrValues = array_flip($this->class->discriminatorMap);

        foreach ($this->class->getSubClasses() as $subclassName) {
            $values[] = $this->conn->quote($discrValues[$subclassName]);
        }

        $discrColumn      = $this->class->discriminatorColumn;
        $discrColumnType  = $discrColumn->getType();
        $tableAlias       = $this->getSQLTableAlias($discrColumn->getTableName());
        $quotedColumnName = $this->platform->quoteIdentifier($discrColumn->getColumnName());

        return sprintf(
            '%s IN (%s)',
            $discrColumnType->convertToDatabaseValueSQL($tableAlias . '.' . $quotedColumnName, $this->platform),
            implode(', ', $values)
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
    {
        // Ensure that the filters are applied to the root entity of the inheritance tree
        $targetEntity = $this->em->getClassMetadata($targetEntity->getRootClassName());
        // we don't care about the $targetTableAlias, in a STI there is only one table.

        return parent::generateFilterConditionSQL($targetEntity, $targetTableAlias);
    }
}
