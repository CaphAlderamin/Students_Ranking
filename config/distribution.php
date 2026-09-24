<?php

declare(strict_types=1);

/**
 * Конфигурация распределения (B3-03, ADR-004): маршрут целевика без заявки.
 *
 * Значения: 'profile_module' — первый модуль своего типа (тех/гум по группе) с
 * вакантным местом после фаз 0–3.cascade; иначе свободный модуль школы (R-19).
 *          'free_module'     — сразу свободный модуль школы.
 * Источник флага — ADR-004; Принимает визу в DistributionService. Файл не содержит
 * секретов и трекается git (по образцу config/ranking.php).
 */
return [
    'target_quota_no_application_strategy' => 'profile_module',
];