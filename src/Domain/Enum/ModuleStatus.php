<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Статус модуля (R-05): открыт, если записалось и распределилось БОЛЬШЕ минимума; иначе закрыт.
 */
enum ModuleStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}