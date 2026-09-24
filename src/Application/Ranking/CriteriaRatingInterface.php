<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Model\RankingCandidate;

/**
 * Контракт критериального ключа ранжирования (ADR-002, R-15, R-16; B3-02).
 *
 * Выделен из CriteriaRating (final readonly — PHPUnit не мокает финальные классы),
 * чтобы потребители зависели от абстракции: тест DoD «без пересчёта» мокает
 * интерфейс через createMock и контролирует число вызовов keys() (ровно один
 * на sort()). Логика вычисления остаётся в CriteriaRating; DateBasedRanking
 * и сам CriteriaRating контрактно не меняются сверх implements.
 */
interface CriteriaRatingInterface
{
    /**
     * Критериальные ключи по каждому кандидату пула.
     *
     * @param list<RankingCandidate> $pool пул кандидатов
     *
     * @return array<int, array{tierPaid: bool, score: int}> ключ по studentId
     */
    public function keys(array $pool): array;
}
