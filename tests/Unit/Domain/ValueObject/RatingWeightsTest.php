<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\ValueObject\RatingWeights;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты VO RatingWeights (B1-07): набор ключей и значения по ADR-002 (Q-3,
 * бонусы ≥ 0 по известным ключам, тир paid_over_budget — bool, сумма весов ≈ 1.0 ±0.001).
 */
final class RatingWeightsTest extends TestCase
{
    /**
     * @return array<string, int|float>
     */
    private static function validWeights(): array
    {
        return ['entrance_exams' => 0.4, 'gpa_sem12' => 0.3, 'gpa_basic' => 0.2, 'entrance_test' => 0.1];
    }

    public function testValid(): void
    {
        $weights = self::validWeights();
        $bonuses = ['disability' => 0.5];
        $tiers = ['paid_over_budget' => true];

        $config = new RatingWeights($weights, $bonuses, $tiers, 'min_max_0_100');

        self::assertSame(0.4, $config->weights()['entrance_exams']);
        self::assertSame(0.1, $config->weights()['entrance_test']);
        self::assertSame(0.5, $config->bonuses()['disability']);
        self::assertTrue($config->tiers()['paid_over_budget']);
        self::assertSame('min_max_0_100', $config->normalization());
    }

    public function testIntWeightsNormalizedToFloat(): void
    {
        $config = new RatingWeights(
            ['entrance_exams' => 1, 'gpa_sem12' => 0, 'gpa_basic' => 0, 'entrance_test' => 0],
            ['disability' => 0],
            ['paid_over_budget' => false],
            'min_max_0_100',
        );

        self::assertSame(1.0, $config->weights()['entrance_exams']);
        self::assertSame(0.0, $config->weights()['gpa_basic']);
        self::assertFalse($config->tiers()['paid_over_budget']);
    }

    /**
     * @return iterable<string, array{array<string, int|float>, array<string, int|float>, array<string, mixed>, string}>
     */
    public static function invalidProvider(): iterable
    {
        $weights = ['entrance_exams' => 0.4, 'gpa_sem12' => 0.3, 'gpa_basic' => 0.2, 'entrance_test' => 0.1];
        $bonuses = ['disability' => 0.5];
        $noBonuses = [];
        $tiers = ['paid_over_budget' => true];
        $noTiers = [];

        yield 'сумма весов больше 1' => [
            ['entrance_exams' => 0.4, 'gpa_sem12' => 0.4, 'gpa_basic' => 0.4, 'entrance_test' => 0.4],
            $bonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'сумма весов меньше 1' => [
            ['entrance_exams' => 0.1, 'gpa_sem12' => 0.1, 'gpa_basic' => 0.1, 'entrance_test' => 0.1],
            $bonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'не хватает ключа gpa_basic' => [
            ['entrance_exams' => 0.4, 'gpa_sem12' => 0.3, 'entrance_test' => 0.3],
            $bonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'лишний ключ weights' => [
            $weights + ['extra' => 0.0],
            $bonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'отрицательный вес' => [
            ['entrance_exams' => 0.5, 'gpa_sem12' => 0.3, 'gpa_basic' => 0.3, 'entrance_test' => -0.1],
            $bonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'не хватает ключа disability' => [
            $weights,
            $noBonuses,
            $tiers,
            'min_max_0_100',
        ];
        yield 'отрицательный бонус' => [
            $weights,
            ['disability' => -1.0],
            $tiers,
            'min_max_0_100',
        ];
        yield 'лишний ключ bonuses' => [
            $weights,
            ['disability' => 0.5, 'extra' => 0.0],
            $tiers,
            'min_max_0_100',
        ];
        yield 'не хватает тира' => [
            $weights,
            $bonuses,
            $noTiers,
            'min_max_0_100',
        ];
        yield 'тир не bool' => [
            $weights,
            $bonuses,
            ['paid_over_budget' => 'true'],
            'min_max_0_100',
        ];
        yield 'лишний тир' => [
            $weights,
            $bonuses,
            ['paid_over_budget' => true, 'extra' => false],
            'min_max_0_100',
        ];
        yield 'неизвестная нормализация' => [
            $weights,
            $bonuses,
            $tiers,
            'percent_scale',
        ];
    }

    /**
     * @param array<string, int|float> $weights
     * @param array<string, int|float> $bonuses
     * @param array<string, mixed>     $tiers
     */
    #[DataProvider('invalidProvider')]
    public function testInvalid(array $weights, array $bonuses, array $tiers, string $normalization): void
    {
        self::expectException(\InvalidArgumentException::class);
        new RatingWeights($weights, $bonuses, $tiers, $normalization);
    }
}