<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\RankingCandidate;

/**
 * Стратегия ранжирования «по критериям» (алгоритм 2, R-17; B3-02).
 *
 * Ключ сортировки — по возрастанию приоритета записи (Strategy 2):
 *   1. тир платности DESC (R-15, строгий тир — не аддитивно);
 *   2. взвешенный нормализованный балл DESC (ADR-002; значение берётся из
 *      CriteriaRatingInterface::keys(), расчёт НЕ дублируется);
 *   3. submittedAt ASC (тай-брейк Strategy 2);
 *   4. student.id ASC (id уникален ⇒ детерминированный полный порядок пула).
 *
 * Сортирует КОПИЮ входа — входной пул не мутируется. Пустой пул → пустой результат.
 * Зависимость — от CriteriaRatingInterface (мокабельность для теста DoD).
 */
final readonly class CriteriaBasedRanking implements RankingStrategyInterface
{
    public function __construct(private CriteriaRatingInterface $criteriaRating)
    {
    }

    public function sort(array $pool): array
    {
        $keys = $this->criteriaRating->keys($pool);
        $sorted = $pool;
        usort($sorted, static function (RankingCandidate $a, RankingCandidate $b) use ($keys): int {
            $tierCmp = $keys[$b->student->id]['tierPaid'] <=> $keys[$a->student->id]['tierPaid'];
            if ($tierCmp !== 0) {
                return $tierCmp;
            }

            $scoreCmp = $keys[$b->student->id]['score'] <=> $keys[$a->student->id]['score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $dateCmp = $a->submittedAt <=> $b->submittedAt;
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return $a->student->id <=> $b->student->id;
        });

        return $sorted;
    }

    public function algorithm(): RankingAlgorithm
    {
        return RankingAlgorithm::Criteria;
    }
}
