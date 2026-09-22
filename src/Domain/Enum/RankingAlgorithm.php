<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Алгоритм ранжирования (R-17): по дате подачи заявки или по критериям.
 * Значения совпадают с колонкой `assignments.algorithm` (varchar(16), note date|criteria).
 */
enum RankingAlgorithm: string
{
    case Date = 'date';
    case Criteria = 'criteria';
}