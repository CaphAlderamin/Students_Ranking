<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Тип модуля (R-03): технический / гуманитарный / свободный.
 * Значения совпадают со значениями колонки `modules.module_type` (varchar(16)).
 */
enum ModuleType: string
{
    case Technical = 'technical';
    case Humanitarian = 'humanitarian';
    case Free = 'free';
}