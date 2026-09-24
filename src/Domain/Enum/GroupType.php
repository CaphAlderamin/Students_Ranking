<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Тип группы студентов (R-13): техническая или гуманитарная.
 * Двунаправленно с флагом `student_groups.is_technical` (true = техническая).
 */
enum GroupType: string
{
    case Technical = 'technical';
    case Humanitarian = 'humanitarian';
}