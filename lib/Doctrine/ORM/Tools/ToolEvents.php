<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

class ToolEvents
{
    /**
     * The postGenerateSchemaTable event occurs in SchemaTool#getSchemaFromMetadata()
     * whenever an entity class is transformed into its table representation. It receives
     * the current non-complete Schema instance, the Entity Metadata Class instance and
     * the Schema Table instance of this entity.
     */
    public const postGenerateSchemaTable = 'postGenerateSchemaTable';

    /**
     * The postGenerateSchema event is triggered in SchemaTool#getSchemaFromMetadata()
     * after all entity classes have been transformed into the related Schema structure.
     * The EventArgs contain the EntityManager and the created Schema instance.
     */
    public const postGenerateSchema = 'postGenerateSchema';
}
