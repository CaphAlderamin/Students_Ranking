<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Источник распределения студента на модуль.
 * Конкурс (по рейтингу), свободные модули, целевая квота (вне конкурса — ADR-004/ADR-006).
 * Значения совпадают с колонкой `assignments.source` (varchar(16), note competition|free|target_quota).
 */
enum AssignmentSource: string
{
    case Competition = 'competition';
    case Free = 'free';
    case TargetQuota = 'target_quota';
}