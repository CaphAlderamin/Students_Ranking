<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\RankingCandidate;

/**
 * Стратегия ранжирования «по дате подачи заявки» (алгоритм 1, R-17).
 *
 * Ключ сортировки — по возрастанию приоритета записи:
 *   1. submittedAt ASC;
 *   2. тай-брейк Q-02: критериальный ключ DESC (тир платности, затем взвешенный
 *      нормализованный балл; ADR-002/R-15, вычисляется общим CriteriaRating);
 *   3. student.id ASC (id уникален ⇒ детерминированный полный порядок пула).
 *
 * Сортирует КОПИЮ входа — входной пул не мутируется. Пустой пул → пустой результат.
 */
final readonly class DateBasedRanking implements RankingStrategyInterface
{
    public function __construct(private CriteriaRating $criteriaRating)
    {
    }

    public function sort(array $pool): array
    {
        $keys = $this->criteriaRating->keys($pool);
        $sorted = $pool;
        usort($sorted, static function (RankingCandidate $a, RankingCandidate $b) use ($keys): int {
            $dateCmp = $a->submittedAt <=> $b->submittedAt;
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            $tierCmp = $keys[$b->student->id]['tierPaid'] <=> $keys[$a->student->id]['tierPaid'];
            if ($tierCmp !== 0) {
                return $tierCmp;
            }

            $scoreCmp = $keys[$b->student->id]['score'] <=> $keys[$a->student->id]['score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return $a->student->id <=> $b->student->id;
        });

        return $sorted;
    }

    public function algorithm(): RankingAlgorithm
    {
        return RankingAlgorithm::Date;
    }
}