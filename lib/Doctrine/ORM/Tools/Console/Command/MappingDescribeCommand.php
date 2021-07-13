<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\MappingException;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function array_merge;
use function count;
use function current;
use function get_class;
use function implode;
use function is_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function json_encode;
use function preg_match;
use function preg_quote;
use function print_r;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Show information about mapped entities.
 *
 * @link    www.doctrine-project.org
 */
final class MappingDescribeCommand extends AbstractEntityManagerCommand
{
    protected function configure(): void
    {
        $this->setName('orm:mapping:describe')
             ->addArgument('entityName', InputArgument::REQUIRED, 'Full or partial name of entity')
             ->setDescription('Display information about mapped objects')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->setHelp(<<<'EOT'
The %command.full_name% command describes the metadata for the given full or partial entity class name.

    <info>%command.full_name%</info> My\Namespace\Entity\MyEntity

Or:

    <info>%command.full_name%</info> MyEntity
EOT
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);

        $entityManager = $this->getEntityManager($input);

        $this->displayEntity($input->getArgument('entityName'), $entityManager, $ui);

        return 0;
    }

    /**
     * Display all the mapping information for a single Entity.
     *
     * @param string $entityName Full or partial entity class name
     */
    private function displayEntity(
        string $entityName,
        EntityManagerInterface $entityManager,
        SymfonyStyle $ui
    ): void {
        $metadata = $this->getClassMetadata($entityName, $entityManager);

        $ui->table(
            ['Field', 'Value'],
            array_merge(
                [
                    $this->formatField('Name', $metadata->name),
                    $this->formatField('Root entity name', $metadata->rootEntityName),
                    $this->formatField('Custom generator definition', $metadata->customGeneratorDefinition),
                    $this->formatField('Custom repository class', $metadata->customRepositoryClassName),
                    $this->formatField('Mapped super class?', $metadata->isMappedSuperclass),
                    $this->formatField('Embedded class?', $metadata->isEmbeddedClass),
                    $this->formatField('Parent classes', $metadata->parentClasses),
                    $this->formatField('Sub classes', $metadata->subClasses),
                    $this->formatField('Embedded classes', $metadata->subClasses),
                    $this->formatField('Named queries', $metadata->namedQueries),
                    $this->formatField('Named native queries', $metadata->namedNativeQueries),
                    $this->formatField('SQL result set mappings', $metadata->sqlResultSetMappings),
                    $this->formatField('Identifier', $metadata->identifier),
                    $this->formatField('Inheritance type', $metadata->inheritanceType),
                    $this->formatField('Discriminator column', $metadata->discriminatorColumn),
                    $this->formatField('Discriminator value', $metadata->discriminatorValue),
                    $this->formatField('Discriminator map', $metadata->discriminatorMap),
                    $this->formatField('Generator type', $metadata->generatorType),
                    $this->formatField('Table', $metadata->table),
                    $this->formatField('Composite identifier?', $metadata->isIdentifierComposite),
                    $this->formatField('Foreign identifier?', $metadata->containsForeignIdentifier),
                    $this->formatField('Sequence generator definition', $metadata->sequenceGeneratorDefinition),
                    $this->formatField('Table generator definition', $metadata->tableGeneratorDefinition),
                    $this->formatField('Change tracking policy', $metadata->changeTrackingPolicy),
                    $this->formatField('Versioned?', $metadata->isVersioned),
                    $this->formatField('Version field', $metadata->versionField),
                    $this->formatField('Read only?', $metadata->isReadOnly),

                    $this->formatEntityListeners($metadata->entityListeners),
                ],
                [$this->formatField('Association mappings:', '')],
                $this->formatMappings($metadata->associationMappings),
                [$this->formatField('Field mappings:', '')],
                $this->formatMappings($metadata->fieldMappings)
            )
        );
    }

    /**
     * Return all mapped entity class names
     *
     * @return string[]
     * @psalm-return class-string[]
     */
    private function getMappedEntities(EntityManagerInterface $entityManager): array
    {
        $entityClassNames = $entityManager->getConfiguration()
                                          ->getMetadataDriverImpl()
                                          ->getAllClassNames();

        if (! $entityClassNames) {
            throw new InvalidArgumentException(
                'You do not have any mapped Doctrine ORM entities according to the current configuration. ' .
                'If you have entities or mapping files you should check your mapping configuration for errors.'
            );
        }

        return $entityClassNames;
    }

    /**
     * Return the class metadata for the given entity
     * name
     *
     * @param string $entityName Full or partial entity name
     */
    private function getClassMetadata(
        string $entityName,
        EntityManagerInterface $entityManager
    ): ClassMetadata {
        try {
            return $entityManager->getClassMetadata($entityName);
        } catch (MappingException $e) {
        }

        $matches = array_filter(
            $this->getMappedEntities($entityManager),
            static function ($mappedEntity) use ($entityName) {
                return preg_match('{' . preg_quote($entityName) . '}', $mappedEntity);
            }
        );

        if (! $matches) {
            throw new InvalidArgumentException(sprintf(
                'Could not find any mapped Entity classes matching "%s"',
                $entityName
            ));
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Entity name "%s" is ambiguous, possible matches: "%s"',
                $entityName,
                implode(', ', $matches)
            ));
        }

        return $entityManager->getClassMetadata(current($matches));
    }

    /**
     * Format the given value for console output
     *
     * @param mixed $value
     */
    private function formatValue($value): string
    {
        if ($value === '') {
            return '';
        }

        if ($value === null) {
            return '<comment>Null</comment>';
        }

        if (is_bool($value)) {
            return '<comment>' . ($value ? 'True' : 'False') . '</comment>';
        }

        if (empty($value)) {
            return '<comment>Empty</comment>';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        if (is_object($value)) {
            return sprintf('<%s>', get_class($value));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('Do not know how to format value "%s"', print_r($value, true)));
    }

    /**
     * Add the given label and value to the two column table output
     *
     * @param string $label Label for the value
     * @param mixed  $value A Value to show
     *
     * @return string[]
     * @psalm-return array{0: string, 1: string}
     */
    private function formatField(string $label, $value): array
    {
        if ($value === null) {
            $value = '<comment>None</comment>';
        }

        return [sprintf('<info>%s</info>', $label), $this->formatValue($value)];
    }

    /**
     * Format the association mappings
     *
     * @psalm-param array<string, array<string, mixed>> $propertyMappings
     *
     * @return string[][]
     * @psalm-return list<array{0: string, 1: string}>
     */
    private function formatMappings(array $propertyMappings): array
    {
        $output = [];

        foreach ($propertyMappings as $propertyName => $mapping) {
            $output[] = $this->formatField(sprintf('  %s', $propertyName), '');

            foreach ($mapping as $field => $value) {
                $output[] = $this->formatField(sprintf('    %s', $field), $this->formatValue($value));
            }
        }

        return $output;
    }

    /**
     * Format the entity listeners
     *
     * @psalm-param list<object> $entityListeners
     *
     * @return string[]
     * @psalm-return array{0: string, 1: string}
     */
    private function formatEntityListeners(array $entityListeners): array
    {
        return $this->formatField('Entity listeners', array_map('get_class', $entityListeners));
    }
}
