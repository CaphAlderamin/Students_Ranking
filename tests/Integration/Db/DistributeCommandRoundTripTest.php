<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Infrastructure\Seeder\ApplicationSeeder;
use App\Infrastructure\Seeder\CohortCatalog;
use App\Infrastructure\Seeder\EdgeSeeder;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Infrastructure\Seeder\StudentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный round-trip CLI-команды distribute (B3-05, план v3 §4):
 * реальная БД, migrate+seed (прецедент DistributionSeedRoundTripTest B3-04),
 * затем ПОДПРОЦЕСС `php bin/console distribute --algorithm=date|criteria` —
 * полный путь «CLI → ядро → БД».
 *
 * Проверяет: exit 0, stdout «распределено 400/400», в `assignments` ровно
 * 400 финалов; повторный запуск идемпотентен (снова 400 — замена, план §2.7);
 * обе стратегии переключаемы одной опцией (R-17, DoD ветки).
 */
final class DistributeCommandRoundTripTest extends TestCase
{
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $configPath = dirname(__DIR__, 3) . '/config/db.php';
        self::assertFileExists($configPath, 'config/db.php отсутствует — скопируйте config/db.php.example');

        // Бутстрап по паттерну DistributionSeedRoundTripTest (B3-04).
        $command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/migrate.php');
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('Не удалось применить миграции (шаг неуспешен): ' . implode("\n", $output));
        }

        $factory = \App\Infrastructure\Db\PdoFactory::fromConfigFile($configPath);
        self::$pdo = $factory->create();

        $catalog = new SeedCatalog();
        $edgeSeeder = new EdgeSeeder(self::$pdo, $catalog, new CohortCatalog(), new StudentGenerator());
        $seedReport = $edgeSeeder->seed();
        (new ApplicationSeeder(self::$pdo, new CohortCatalog()))
            ->seed($seedReport['segmentIds'], $seedReport['moduleIds']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['assignments', 'application_items', 'applications', 'module_eligible_schools', 'disciplines', 'modules', 'students', 'student_groups', 'schools'] as $table) {
            self::$pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testCriteriaRoundTripPersistsAndReplaces(): void
    {
        $first = self::runDistribute('criteria');
        self::assertSame(0, $first['exit'], 'exit 0: ' . implode("\n", $first['output']));
        self::assertStringContainsString(
            'Распределено студентов: 400/400 (R-11)',
            implode("\n", $first['output']),
            'stdout содержит итог сводки',
        );
        self::assertSame(400, self::assignmentCount(), 'в assignments ровно 400 финалов (R-11)');

        // Повторный запуск идемпотентен: снова 400 (замена старых записей, §2.7).
        $second = self::runDistribute('criteria');
        self::assertSame(0, $second['exit'], 'повторный exit 0: ' . implode("\n", $second['output']));
        self::assertSame(400, self::assignmentCount(), 'повторный запуск не дублирует (замена, §2.7)');
    }

    public function testDateRoundTripPersists(): void
    {
        $result = self::runDistribute('date');
        self::assertSame(0, $result['exit'], 'exit 0: ' . implode("\n", $result['output']));
        self::assertStringContainsString(
            'Алгоритм: date',
            implode("\n", $result['output']),
            'стандартный вывод содержит выбранный алгоритм',
        );
        self::assertSame(400, self::assignmentCount(), 'в assignments ровно 400 финалов (R-11)');
    }

    /**
     * @return array{exit: int, output: list<string>}
     */
    private static function runDistribute(string $algorithm): array
    {
        $command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/console')
            . ' distribute --algorithm=' . $algorithm;
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return ['exit' => $exitCode, 'output' => $output];
    }

    private static function assignmentCount(): int
    {
        $stmt = self::$pdo->query('SELECT COUNT(*) FROM assignments');
        $value = $stmt === false ? false : $stmt->fetchColumn();

        return (int) $value;
    }
}