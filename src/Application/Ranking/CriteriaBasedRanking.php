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
    /**
     * Стратегия ранжирования «по критериям» — полный критериальный порядок (алгоритм 2, R-17).
     *
     * @param CriteriaRatingInterface $criteriaRating критериальный ключ (ADR-002, R-15)
     */
    public function __construct(private CriteriaRatingInterface $criteriaRating)
    {
    }

    /**
     * Сортирует пул кандидатов по критериальному ключу (Strategy 2, R-17).
     *
     * Порядок: тир платности DESC → взвешенный нормализованный балл DESC →
     * дата подачи ASC → id ASC (детерминированный полный порядок).
     * Сортируется копия — входной пул не мутируется.
     *
     * @param list<RankingCandidate> $pool пул кандидатов
     *
     * @return list<RankingCandidate> пул по убыванию приоритета записи
     */
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

    /**
     * Алгоритм стратегии (для сводки CLI/веба).
     *
     * @return RankingAlgorithm признак algorithm criteria
     */
    public function algorithm(): RankingAlgorithm
    {
        return RankingAlgorithm::Criteria;
    }
}
