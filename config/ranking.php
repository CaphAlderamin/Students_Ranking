<?php

declare(strict_types=1);

/**
 * Конфигурация рейтинга (ADR-002): веса компонентов, бонусы, тиры, нормализация.
 *
 * Единственный источник весов для CriteriaRating (B3-02). Файл не содержит
 * секретов и трекается git (в отличие от config/db.php, исключённого в .gitignore).
 * Значения валидируются RatingWeights при построении объекта; при расхождении
 * с ADR-002 приоритет — за ADR-002.
 */
return [
    'weights' => [
        'entrance_exams' => 0.40,
        'gpa_sem12' => 0.25,
        'gpa_basic' => 0.20,
        'entrance_test' => 0.15,
    ],
    'bonuses' => [
        'disability' => 10.0,
    ],
    'tiers' => [
        'paid_over_budget' => true,
    ],
    'normalization' => 'min_max_0_100',
];
