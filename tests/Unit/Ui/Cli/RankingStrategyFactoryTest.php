<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Cli;

use App\Application\Ranking\CriteriaBasedRanking;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\DateBasedRanking;
use App\Domain\ValueObject\RatingWeights;
use App\Ui\Cli\RankingStrategyFactory;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты чистой фабрики стратегий (B3-05, план v3 §2.6/§4): маппинг
 * CLI-опции `--algorithm=date|criteria` на реализацию RankingStrategyInterface
 * (R-17), невалидное значение → \InvalidArgumentException. Без БД и конфигов.
 */
final class RankingStrategyFactoryTest extends TestCase
{
    /** Критериальный ключ (ADR-002) из конфиг-значений плана B3-02. */
    private CriteriaRating $rating;

    protected function setUp(): void
    {
        $this->rating = new CriteriaRating(new RatingWeights(
            weights: ['entrance_exams' => 0.40, 'gpa_sem12' => 0.25, 'gpa_basic' => 0.20, 'entrance_test' => 0.15],
            bonuses: ['disability' => 10.0],
            tiers: ['paid_over_budget' => true],
            normalization: 'min_max_0_100',
        ));
    }

    public function testDateAlgorithmMapping(): void
    {
        self::assertInstanceOf(
            DateBasedRanking::class,
            RankingStrategyFactory::createStrategy(RankingStrategyFactory::DATE_ALGORITHM, $this->rating),
        );
    }

    public function testCriteriaAlgorithmMapping(): void
    {
        self::assertInstanceOf(
            CriteriaBasedRanking::class,
            RankingStrategyFactory::createStrategy(RankingStrategyFactory::CRITERIA_ALGORITHM, $this->rating),
        );
    }

    public function testUnknownAlgorithmRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RankingStrategyFactory::createStrategy('bogus', $this->rating);
    }
}