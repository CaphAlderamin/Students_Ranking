<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Кандидат конкурсного ранжирования (ADR-007).
 *
 * DTO пула стратегий: Student плюс момент подачи заявки. Student не содержит
 * `submittedAt` (см. ADR-007), поэтому временная метка передаётся здесь.
 * Типы дают защиту; новых бизнес-правил не добавляет.
 */
final readonly class RankingCandidate
{
    public function __construct(
        public Student $student,
        public \DateTimeImmutable $submittedAt,
    ) {
    }
}