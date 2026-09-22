<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\RankingAlgorithm;

/**
 * Итоговое распределение студента на модуль (таблица `assignments`).
 *
 * Замечание (контракты): в БД сохраняются только final-назначения; статусы provisional/void
 * — внутреннее состояние DistributionService, в схему не переносятся (колонки `status` нет).
 */
final readonly class Assignment
{
    public int $studentId;
    public int $moduleId;
    public RankingAlgorithm $algorithm;
    public AssignmentSource $source;

    /** Позиция в рейтинге (NULL — неконкурсные источники: free/target_quota). */
    public ?int $rank;

    public function __construct(
        int $studentId,
        int $moduleId,
        RankingAlgorithm $algorithm,
        AssignmentSource $source,
        ?int $rank,
    ) {
        if ($studentId < 1) {
            throw new \InvalidArgumentException('Assignment: studentId должен быть положительным числом, получено: ' . $studentId);
        }
        if ($moduleId < 1) {
            throw new \InvalidArgumentException('Assignment: moduleId должен быть положительным числом, получено: ' . $moduleId);
        }
        if ($rank !== null && $rank < 1) {
            throw new \InvalidArgumentException('Assignment: rank должен быть ≥ 1 или null, получено: ' . $rank);
        }

        $this->studentId = $studentId;
        $this->moduleId = $moduleId;
        $this->algorithm = $algorithm;
        $this->source = $source;
        $this->rank = $rank;
    }
}