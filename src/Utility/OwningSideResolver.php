<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadataFactoryInterface;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyAssociationMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;

use function assert;

final class OwningSideResolver
{
    private function __construct()
    {
    }

    /**
     * @param A $maybeOwningSide
     *
     * @return (A is ManyToManyAssociationMapping ? ManyToManyOwningSideMapping : (
     *     A is OneToOneAssociationMapping ? OneToOneOwningSideMapping : (
     *     A is OneToManyAssociationMapping ? ManyToOneAssociationMapping : (
     *     A is ManyToOneAssociationMapping ? ManyToOneAssociationMapping :
     *     ManyToManyOwningSideMapping|OneToOneOwningSideMapping|ManyToOneAssociationMapping
     * ))))
     *
     * @template A of AssociationMapping
     */
    public static function resolve(ClassMetadataFactoryInterface $metadataFactory, AssociationMapping $maybeOwningSide): OwningSideMapping
    {
        if ($maybeOwningSide instanceof OwningSideMapping) {
            assert($maybeOwningSide instanceof ManyToManyOwningSideMapping ||
                $maybeOwningSide instanceof OneToOneOwningSideMapping ||
                $maybeOwningSide instanceof ManyToOneAssociationMapping);

            return $maybeOwningSide;
        }

        assert($maybeOwningSide instanceof InverseSideMapping);

        $owningSide = $metadataFactory->getMetadataFor($maybeOwningSide->targetEntity)
            ->associationMappings[$maybeOwningSide->mappedBy];

        assert($owningSide instanceof ManyToManyOwningSideMapping ||
            $owningSide instanceof OneToOneOwningSideMapping ||
            $owningSide instanceof ManyToOneAssociationMapping);

        return $owningSide;
    }
}
