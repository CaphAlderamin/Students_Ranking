<?php

declare(strict_types=1);

/**
 * Сброс базы данных к пустому состоянию (DROP всех таблиц соединения).
 *
 * Запуск: вызывается из scripts/db-reset.sh внутри php-контейнера
 * (docker compose exec -T php sh /app/scripts/db-reset.sh [migrate|seed]).
 *
 * Режимы (по целям Makefile):
 *  - без аргумента            — только DROP всех таблиц (БД пустая, без схемы);
 *  - migrate                  — DROP + повторное применение миграций
 *                             (scripts/migrate.php: журнал заново, DDL заново);
 *  - seed                     — DROP + миграции + сидер (каталог B2-02/B2-03 + заявки B2-04).
 *
 * DROP выполняется при отключённых проверках внешних ключей; список таблиц
 * берётся из information_schema по имени схемы соединения — чужие таблицы
 * той же БД не трогаются.
 */

use App\Infrastructure\Db\PdoFactory;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$pdo = PdoFactory::fromConfigFile($root . '/config/db.php')->create();

$listStmt = $pdo->prepare(
    'SELECT table_name
     FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = ?',
);
$listStmt->execute(['BASE TABLE']);
/** @var list<mixed> $values */
$values = $listStmt->fetchAll(\PDO::FETCH_COLUMN);

$tableNames = [];
foreach ($values as $value) {
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, '[db-reset] ошибка схемы: имя таблицы не является непустой строкой' . "\n");
        exit(1);
    }
    $tableNames[] = $value;
}

$mode = $argv[1] ?? 'drop';
if (!in_array($mode, ['drop', 'migrate', 'seed'], true)) {
    fwrite(STDERR, '[db-reset] неизвестный режим "' . $mode . '": ожидается drop, migrate или seed' . "\n");
    exit(1);
}

if ($tableNames === []) {
    echo "[db-reset] таблиц в схеме нет: сбрасывать нечего\n";
} else {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $statement = 'DROP TABLE IF EXISTS `' . implode('`, `', $tableNames) . '`';
    $pdo->exec($statement);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    echo '[db-reset] DROP выполнено: ' . count($tableNames) . ' таблиц удалено ('
        . implode(', ', $tableNames) . ")\n";
}

if ($mode === 'drop') {
    echo "[db-reset] база сброшена до пустой схемы (миграции/сидер не выполнялись)\n";
    exit(0);
}

passthru('php /app/scripts/migrate.php', $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}

if ($mode === 'seed') {
    passthru('php /app/scripts/seed.php', $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

echo "[db-reset] режим '{$mode}' выполнен: база пересоздана\n";
exit(0);