<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Export;

/**
 * Test case for PhpClassMetadataExporterTest
 *
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        http://www.phpdoctrine.org
 * @since       2.0
 * @version     $Revision$
 */
class PhpClassMetadataExporterTest extends AbstractClassMetadataExporterTest
{
    protected function getType()
    {
        return 'php';
    }
}
