<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\Persistence\Mapping\MappingException;
use InvalidArgumentException;
use JsonException;
use Override;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function current;
use function get_debug_type;
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
use function str_replace;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Show information about mapped entities.
 *
 * @link    www.doctrine-project.org
 */
final class MappingDescribeCommand extends AbstractEntityManagerCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setName('orm:mapping:describe')
            ->addArgument('entityName', InputArgument::REQUIRED, 'Full or partial name of entity')
            ->setDescription('Display information about mapped objects')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format (text, json)',
                MappingDescribeCommandFormat::TEXT->value,
                array_map(static fn (MappingDescribeCommandFormat $format) => $format->value, MappingDescribeCommandFormat::cases()),
            )
             ->setHelp(<<<'EOT'
The %command.full_name% command describes the metadata for the given full or partial entity class name.

    <info>%command.full_name%</info> My\Namespace\Entity\MyEntity

Or:

    <info>%command.full_name%</info> MyEntity
    
To output the metadata in JSON format, use the <info>--format</info> option:
  <info>%command.full_name% My\Namespace\Entity\MyEntity --format=json</info>

To use a specific entity manager (e.g., for multi-DB projects), use the <info>--em</info> option:
  <info>%command.full_name% My\Namespace\Entity\MyEntity --em=my_custom_entity_manager</info>

EOT);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);

        $format = MappingDescribeCommandFormat::from($input->getOption('format'));

        $entityManager = $this->getEntityManager($input);

        $this->displayEntity($input->getArgument('entityName'), $entityManager, $ui, $format);

        return 0;
    }

    #[Override]
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('entityName')) {
            $entityManager = $this->getEntityManager($input);

            $entities = array_map(
                static fn (string $fqcn) => str_replace('\\', '\\\\', $fqcn),
                $this->getMappedEntities($entityManager),
            );

            $suggestions->suggestValues(array_values($entities));
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(array_map(static fn (MappingDescribeCommandFormat $format) => $format->value, MappingDescribeCommandFormat::cases()));
        }
    }

    /**
     * Display all the mapping information for a single Entity.
     *
     * @param string $entityName Full or partial entity class name
     */
    private function displayEntity(
        string $entityName,
        EntityManagerInterface $entityManager,
        SymfonyStyle $ui,
        MappingDescribeCommandFormat $format,
    ): void {
        $metadata = $this->getClassMetadata($entityName, $entityManager);

        if ($format === MappingDescribeCommandFormat::JSON) {
            $ui->text(json_encode(
                [
                    'name' => $metadata->name,
                    'rootEntityName' => $metadata->rootEntityName,
                    'customGeneratorDefinition' => $this->formatValueAsJson($metadata->customGeneratorDefinition),
                    'customRepositoryClassName' => $metadata->customRepositoryClassName,
                    'isMappedSuperclass' => $metadata->isMappedSuperclass,
                    'isEmbeddedClass' => $metadata->isEmbeddedClass,
                    'parentClasses' => $metadata->parentClasses,
                    'subClasses' => $metadata->subClasses,
                    'embeddedClasses' => $metadata->embeddedClasses,
                    'identifier' => $metadata->identifier,
                    'inheritanceType' => $metadata->inheritanceType,
                    'discriminatorColumn' => $this->formatValueAsJson($metadata->discriminatorColumn),
                    'discriminatorValue' => $metadata->discriminatorValue,
                    'discriminatorMap' => $metadata->discriminatorMap,
                    'generatorType' => $metadata->generatorType,
                    'table' => $this->formatValueAsJson($metadata->table),
                    'isIdentifierComposite' => $metadata->isIdentifierComposite,
                    'containsForeignIdentifier' => $metadata->containsForeignIdentifier,
                    'containsEnumIdentifier' => $metadata->containsEnumIdentifier,
                    'sequenceGeneratorDefinition' => $this->formatValueAsJson($metadata->sequenceGeneratorDefinition),
                    'changeTrackingPolicy' => $metadata->changeTrackingPolicy,
                    'isVersioned' => $metadata->isVersioned,
                    'versionField' => $metadata->versionField,
                    'isReadOnly' => $metadata->isReadOnly,
                    'entityListeners' => $metadata->entityListeners,
                    'associationMappings' => $this->formatMappingsAsJson($metadata->associationMappings),
                    'fieldMappings' => $this->formatMappingsAsJson($metadata->fieldMappings),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            return;
        }

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
                    $this->formatField('Embedded classes', $metadata->embeddedClasses),
                    $this->formatField('Identifier', $metadata->identifier),
                    $this->formatField('Inheritance type', $metadata->inheritanceType),
                    $this->formatField('Discriminator column', $metadata->discriminatorColumn),
                    $this->formatField('Discriminator value', $metadata->discriminatorValue),
                    $this->formatField('Discriminator map', $metadata->discriminatorMap),
                    $this->formatField('Generator type', $metadata->generatorType),
                    $this->formatField('Table', $metadata->table),
                    $this->formatField('Composite identifier?', $metadata->isIdentifierComposite),
                    $this->formatField('Foreign identifier?', $metadata->containsForeignIdentifier),
                    $this->formatField('Enum identifier?', $metadata->containsEnumIdentifier),
                    $this->formatField('Sequence generator definition', $metadata->sequenceGeneratorDefinition),
                    $this->formatField('Change tracking policy', $metadata->changeTrackingPolicy),
                    $this->formatField('Versioned?', $metadata->isVersioned),
                    $this->formatField('Version field', $metadata->versionField),
                    $this->formatField('Read only?', $metadata->isReadOnly),

                    $this->formatEntityListeners($metadata->entityListeners),
                ],
                [$this->formatField('Association mappings:', '')],
                $this->formatMappings($metadata->associationMappings),
                [$this->formatField('Field mappings:', '')],
                $this->formatMappings($metadata->fieldMappings),
            ),
        );
    }

    /**
     * Return all mapped entity class names
     *
     * @return class-string[]
     */
    private function getMappedEntities(EntityManagerInterface $entityManager): array
    {
        $entityClassNames = $entityManager->getConfiguration()
                                          ->getMetadataDriverImpl()
                                          ->getAllClassNames();

        if (! $entityClassNames) {
            throw new InvalidArgumentException(
                'You do not have any mapped Doctrine ORM entities according to the current configuration. ' .
                'If you have entities or mapping files you should check your mapping configuration for errors.',
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
        EntityManagerInterface $entityManager,
    ): ClassMetadata {
        try {
            return $entityManager->getClassMetadata($entityName);
        } catch (MappingException) {
        }

        $matches = array_filter(
            $this->getMappedEntities($entityManager),
            static fn ($mappedEntity) => preg_match('{' . preg_quote($entityName) . '}', $mappedEntity),
        );

        if (! $matches) {
            throw new InvalidArgumentException(sprintf(
                'Could not find any mapped Entity classes matching "%s"',
                $entityName,
            ));
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Entity name "%s" is ambiguous, possible matches: "%s"',
                $entityName,
                implode(', ', $matches),
            ));
        }

        return $entityManager->getClassMetadata(current($matches));
    }

    /**
     * Format the given value for console output
     */
    private function formatValue(mixed $value): string
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
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );
        }

        if (is_object($value)) {
            return sprintf('<%s>', get_debug_type($value));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('Do not know how to format value "%s"', print_r($value, true)));
    }

    /** @throws JsonException */
    private function formatValueAsJson(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->formatValueAsJson($v);
            }
        }

        return $value;
    }

    /**
     * Add the given label and value to the two column table output
     *
     * @param string $label Label for the value
     * @param mixed  $value A Value to show
     *
     * @return string[]
     * @phpstan-return array{0: string, 1: string}
     */
    private function formatField(string $label, mixed $value): array
    {
        if ($value === null) {
            $value = '<comment>None</comment>';
        }

        return [sprintf('<info>%s</info>', $label), $this->formatValue($value)];
    }

    /**
     * Format the association mappings
     *
     * @phpstan-param array<string, FieldMapping|AssociationMapping> $propertyMappings
     *
     * @return string[][]
     * @phpstan-return list<array{0: string, 1: string}>
     */
    private function formatMappings(array $propertyMappings): array
    {
        $output = [];

        foreach ($propertyMappings as $propertyName => $mapping) {
            $output[] = $this->formatField(sprintf('  %s', $propertyName), '');

            foreach ((array) $mapping as $field => $value) {
                $output[] = $this->formatField(sprintf('    %s', $field), $this->formatValue($value));
            }
        }

        return $output;
    }

    /**
     * @param array<string, FieldMapping|AssociationMapping> $propertyMappings
     *
     * @return array<string, mixed>
     */
    private function formatMappingsAsJson(array $propertyMappings): array
    {
        $output = [];

        foreach ($propertyMappings as $propertyName => $mapping) {
            $output[$propertyName] = $this->formatValueAsJson((array) $mapping);
        }

        return $output;
    }

    /**
     * Format the entity listeners
     *
     * @phpstan-param array<string, list<array{class: class-string, method: string}>> $entityListeners
     *
     * @return string[]
     * @phpstan-return array{0: string, 1: string}
     */
    private function formatEntityListeners(array $entityListeners): array
    {
        $listeners = [];

        foreach ($entityListeners as $eventName => $eventListeners) {
            foreach ($eventListeners as $listener) {
                $listeners[] = sprintf('%s: %s::%s', $eventName, $listener['class'], $listener['method']);
            }
        }

        return $this->formatField('Entity listeners', $listeners);
    }
}
