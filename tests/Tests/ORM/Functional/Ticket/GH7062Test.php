<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function assert;

class GH7062Test extends OrmFunctionalTestCase
{
    private const string SEASON_ID = 'season_18';
    private const string TEAM_ID   = 'team_A';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                GH7062Team::class,
                GH7062Season::class,
                GH7062Ranking::class,
                GH7062RankingPosition::class,
            ],
        );
    }

    #[Group('GH-7062')]
    public function testEntityWithAssociationKeyIdentityCanBeUpdated(): void
    {
        $this->createInitialRankingWithRelatedEntities();
        $this->modifyRanking();
        $this->verifyRanking();
    }

    private function createInitialRankingWithRelatedEntities(): void
    {
        $team   = new GH7062Team(self::TEAM_ID);
        $season = new GH7062Season(self::SEASON_ID);

        $season->ranking = new GH7062Ranking($season, [$team]);

        $this->_em->persist($team);
        $this->_em->persist($season);
        $this->_em->flush();
        $this->_em->clear();

        foreach ($season->ranking->positions as $position) {
            self::assertSame(0, $position->points);
        }
    }

    private function modifyRanking(): void
    {
        $ranking = $this->_em->find(GH7062Ranking::class, self::SEASON_ID);
        assert($ranking instanceof GH7062Ranking);

        foreach ($ranking->positions as $position) {
            $position->points += 3;
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    private function verifyRanking(): void
    {
        $season = $this->_em->find(GH7062Season::class, self::SEASON_ID);
        assert($season instanceof GH7062Season);
        self::assertInstanceOf(GH7062Season::class, $season);

        $ranking = $season->ranking;
        self::assertInstanceOf(GH7062Ranking::class, $ranking);

        foreach ($ranking->positions as $position) {
            self::assertSame(3, $position->points);
        }
    }
}

/**
 * Simple Entity whose identity is defined through another Entity (Season)
 */
#[Table(name: 'soccer_rankings')]
#[Entity]
class GH7062Ranking
{
    /**
     * @var Collection|GH7062RankingPosition[]
     * @phpstan-var Collection<GH7062RankingPosition>
     */
    #[OneToMany(targetEntity: GH7062RankingPosition::class, mappedBy: 'ranking', cascade: ['all'])]
    public $positions;

    /** @param GH7062Team[] $teams */
    public function __construct(
        #[Id]
        #[OneToOne(targetEntity: GH7062Season::class, inversedBy: 'ranking')]
        #[JoinColumn(name: 'season', referencedColumnName: 'id')]
        public GH7062Season $season,
        array $teams,
    ) {
        $this->positions = new ArrayCollection();

        foreach ($teams as $team) {
            $this->positions[] = new GH7062RankingPosition($this, $team);
        }
    }
}

/**
 * Entity which serves as a identity provider for other entities
 */
#[Table(name: 'soccer_seasons')]
#[Entity]
class GH7062Season
{
    /** @var GH7062Ranking|null */
    #[OneToOne(targetEntity: GH7062Ranking::class, mappedBy: 'season', cascade: ['all'])]
    public $ranking;

    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 255)]
        public string $id,
    ) {
    }
}

/**
 * Entity which serves as a identity provider for other entities
 */
#[Table(name: 'soccer_teams')]
#[Entity]
class GH7062Team
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 255)]
        public string $id,
    ) {
    }
}

/**
 * Entity whose identity is defined through two other entities
 */
#[Table(name: 'soccer_ranking_positions')]
#[Entity]
class GH7062RankingPosition
{
    /** @var int */
    #[Column(type: 'integer')]
    public $points;

    public function __construct(
        #[Id]
        #[ManyToOne(targetEntity: GH7062Ranking::class, inversedBy: 'positions')]
        #[JoinColumn(name: 'season', referencedColumnName: 'season')]
        public GH7062Ranking $ranking,
        #[Id]
        #[ManyToOne(targetEntity: GH7062Team::class)]
        #[JoinColumn(name: 'team_id', referencedColumnName: 'id')]
        public GH7062Team $team,
    ) {
        $this->points = 0;
    }
}
