<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\RankingCandidate;

/**
 * Стратегия ранжирования конкурсного пула (R-17, contracts.md).
 *
 * Возвращает порядок обхода пула: первый элемент — высший приоритет записи.
 * Реализации обязаны быть детерминированными и не мутировать входной массив.
 * Пул — кандидаты RankingCandidate (ADR-007: Student + момент подачи заявки;
 * в ранжировании участвуют только студенты с заявкой).
 */
interface RankingStrategyInterface
{
    /**
     * Сортирует пул кандидатов по ключу стратегии (полный порядок).
     *
     * @param list<RankingCandidate> $pool пул кандидатов (не мутируется)
     *
     * @return list<RankingCandidate> пул по убыванию приоритета записи
     */
    public function sort(array $pool): array;

    /** Алгоритм ранжирования (R-17). */
    public function algorithm(): RankingAlgorithm;
}