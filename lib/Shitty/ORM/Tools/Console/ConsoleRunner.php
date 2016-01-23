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

namespace Shitty\ORM\Tools\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Shitty\ORM\Version;
use Shitty\ORM\EntityManagerInterface;

use Shitty\DBAL\Tools\Console\Helper\ConnectionHelper;
use Shitty\ORM\Tools\Console\Helper\EntityManagerHelper;

/**
 * Handles running the Console Tools inside Symfony Console context.
 */
class ConsoleRunner
{
    /**
     * Create a Symfony Console HelperSet
     *
     * @param EntityManagerInterface $entityManager
     * @return HelperSet
     */
    public static function createHelperSet(EntityManagerInterface $entityManager)
    {
        return new HelperSet(array(
            'db' => new ConnectionHelper($entityManager->getConnection()),
            'em' => new EntityManagerHelper($entityManager)
        ));
    }

    /**
     * Runs console with the given helperset.
     *
     * @param \Symfony\Component\Console\Helper\HelperSet  $helperSet
     * @param \Symfony\Component\Console\Command\Command[] $commands
     *
     * @return void
     */
    static public function run(HelperSet $helperSet, $commands = array())
    {
        $cli = self::createApplication($helperSet, $commands);
        $cli->run();
    }

    /**
     * Creates a console application with the given helperset and
     * optional commands.
     *
     * @param \Symfony\Component\Console\Helper\HelperSet $helperSet
     * @param array                                       $commands
     *
     * @return \Symfony\Component\Console\Application
     */
    static public function createApplication(HelperSet $helperSet, $commands = array())
    {
        $cli = new Application('Doctrine Command Line Interface', Version::VERSION);
        $cli->setCatchExceptions(true);
        $cli->setHelperSet($helperSet);
        self::addCommands($cli);
        $cli->addCommands($commands);

        return $cli;
    }

    /**
     * @param Application $cli
     *
     * @return void
     */
    static public function addCommands(Application $cli)
    {
        $cli->addCommands(array(
            // DBAL Commands
            new \Shitty\DBAL\Tools\Console\Command\RunSqlCommand(),
            new \Shitty\DBAL\Tools\Console\Command\ImportCommand(),

            // ORM Commands
            new \Shitty\ORM\Tools\Console\Command\ClearCache\MetadataCommand(),
            new \Shitty\ORM\Tools\Console\Command\ClearCache\ResultCommand(),
            new \Shitty\ORM\Tools\Console\Command\ClearCache\QueryCommand(),
            new \Shitty\ORM\Tools\Console\Command\SchemaTool\CreateCommand(),
            new \Shitty\ORM\Tools\Console\Command\SchemaTool\UpdateCommand(),
            new \Shitty\ORM\Tools\Console\Command\SchemaTool\DropCommand(),
            new \Shitty\ORM\Tools\Console\Command\EnsureProductionSettingsCommand(),
            new \Shitty\ORM\Tools\Console\Command\ConvertDoctrine1SchemaCommand(),
            new \Shitty\ORM\Tools\Console\Command\GenerateRepositoriesCommand(),
            new \Shitty\ORM\Tools\Console\Command\GenerateEntitiesCommand(),
            new \Shitty\ORM\Tools\Console\Command\GenerateProxiesCommand(),
            new \Shitty\ORM\Tools\Console\Command\ConvertMappingCommand(),
            new \Shitty\ORM\Tools\Console\Command\RunDqlCommand(),
            new \Shitty\ORM\Tools\Console\Command\ValidateSchemaCommand(),
            new \Shitty\ORM\Tools\Console\Command\InfoCommand(),
            new \Shitty\ORM\Tools\Console\Command\MappingDescribeCommand(),
        ));
    }

    static public function printCliConfigTemplate()
    {
        echo <<<'HELP'
You are missing a "cli-config.php" or "config/cli-config.php" file in your
project, which is required to get the Doctrine Console working. You can use the
following sample as a template:

<?php
use Doctrine\ORM\Tools\Console\ConsoleRunner;

// replace with file to your own project bootstrap
require_once 'bootstrap.php';

// replace with mechanism to retrieve EntityManager in your app
$entityManager = GetEntityManager();

return ConsoleRunner::createHelperSet($entityManager);

HELP;

    }
}
