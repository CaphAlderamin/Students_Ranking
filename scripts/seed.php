<?php

declare(strict_types=1);

/**
 * Раннер сидера (B2-02).
 *
 * Запуск: вызывается из scripts/seed.sh внутри php-контейнера
 * (docker compose exec -T php sh /app/scripts/seed.sh).
 *
 * Протокол (план B2-02 §2.3): TRUNCATE всех сидовых таблиц с отключёнными
 * FOREIGN_KEY_CHECKS + наполнение из SeedCatalog. Журнал schema_migrations
 * не трогается — повторный запуск детерминирован.
 */

use App\Infrastructure\Seeder\DataSeeder;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$seeder = DataSeeder::fromConfigFile($root . '/config/db.php');
$seeder->seed();

echo "[B2-02] сидер выполнен: каталог (7 школ, 400 студентов, "
    . '8 тех / 5 гум / 3 свободных модуля, дисциплины 3/4/5) наполнен детерминированно'
    . "\n";
exit(0);