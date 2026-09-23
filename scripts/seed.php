<?php

declare(strict_types=1);

/**
 * Раннер сидера (B2-03).
 *
 * Запуск: вызывается из scripts/seed.sh внутри php-контейнера
 * (docker compose exec -T php sh /app/scripts/seed.sh).
 *
 * Протокол (план B2-03 §2): базовый каталог B2-02 (DataSeeder) + двухстадийная
 * схема краевых когорт (CohortCatalog/EdgeSeeder) + недоборный модуль +
 * инвариант Σmax_free ≥ W + H. Журнал schema_migrations не трогается —
 * повторный запуск детерминирован.
 */

use App\Infrastructure\Seeder\EdgeSeeder;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$report = EdgeSeeder::fromConfigFile($root . '/config/db.php')->seed();

$moduleIds = $report['moduleIds'];

echo "[B2-03] сидер выполнен: каталог (7 школ, 400 студентов, 8 тех / 5 гум / 3 свободных"
    . " + 1 недоборный модуль, дисциплины 3/4/5) и краевые когорты наполнены детерминированно;"
    . ' недоборный модуль: id=' . $moduleIds[0]
    . "\n";
exit(0);