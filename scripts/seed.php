<?php

declare(strict_types=1);

/**
 * Раннер сидера (B2-03 + B2-04).
 *
 * Запуск: вызывается из scripts/seed.sh внутри php-контейнера
 * (docker compose exec -T php sh /app/scripts/seed.sh).
 *
 * Протокол (план B2-03 §2 + B2-04 §2): базовый каталог B2-02 (DataSeeder) +
 * двухстадийная схема краевых когорт (CohortCatalog/EdgeSeeder) + недоборный
 * модуль + инвариант Σmax_free ≥ W + H (B2-03); затем заявки B2-04
 * (ApplicationSeeder): 352 заявки / 1056 items, окно R-12, только свой тип
 * (R-13), профили D=6 и T=12. Журнал schema_migrations не трогается —
 * повторный запуск детерминирован.
 */

use App\Infrastructure\Seeder\ApplicationSeeder;
use App\Infrastructure\Seeder\EdgeSeeder;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$edgeReport = EdgeSeeder::fromConfigFile($root . '/config/db.php')->seed();

$moduleIds = $edgeReport['moduleIds'];

echo "[B2-03] сидер выполнен: каталог (7 школ, 400 студентов, 8 тех / 5 гум / 3 свободных"
    . " + 1 недоборный модуль, дисциплины 3/4/5) и краевые когорты наполнены детерминированно;"
    . ' недоборный модуль: id=' . $moduleIds[0]
    . "\n";

$applicationReport = ApplicationSeeder::fromConfigFile($root . '/config/db.php')
    ->seed($edgeReport['segmentIds'], $moduleIds);

echo "[B2-04] заявки сгенерированы: applications=" . $applicationReport['applications']
    . ", items=" . $applicationReport['items']
    . ', окно подачи ' . $applicationReport['windowStart'] . ' .. ' . $applicationReport['windowEnd']
    . ", заявителей недоборного модуля=" . $applicationReport['underEnrollmentCount']
    . "\n";
exit(0);