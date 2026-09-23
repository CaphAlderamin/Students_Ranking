<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\ModuleType;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\Application;
use App\Domain\Model\Assignment;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Infrastructure\Db\PdoApplicationRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Db\PdoModuleRepository;
use App\Infrastructure\Db\PdoStudentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест чтения/записи репозиториев (B2-01, критерий приёмки).
 *
 * Требует запущенный MySQL и config/db.php. Схема гарантируется прогоном
 * scripts/migrate.php в setUpBeforeClass (раннер B1-05 идемпотентен —
 * журнал schema_migrations). Фикстура изолирована кодом школы QA_B2_01
 * и вычищается в tearDown в порядке внешних ключей.
 *
 * @see структура схемы — database/schema.dbml
 */
final class RepositoryRoundTripTest extends TestCase
{
    private const string SCHOOL_CODE = 'QA_B2_01';
    private const string SCHOOL_NAME = 'QA-школа B2-01';
    private const string GROUP_NAME = 'QA-группа 2025';
    private const string GROUP_NAME_2024 = 'QA-группа 2024';
    private const int ADMISSION_YEAR = 2025;
    private const int ADMISSION_YEAR_OTHER = 2024;
    private const string ACADEMIC_YEAR = '2025-2026';

    private static PdoFactory $factory;
    private static \PDO $pdo;

    /** Идентификаторы фикстуры (текущий прогон). */
    private static int $schoolId;
    private static int $groupId;
    private static int $groupId2024;
    /** @var list<int> */
    private static array $studentIds;
    /** @var list<int> */
    private static array $moduleIds;
    private static int $applicationId;
    private static string $configPath;

    public static function setUpBeforeClass(): void
    {
        self::$configPath = dirname(__DIR__, 3) . '/config/db.php';
        self::assertFileExists(self::$configPath, 'config/db.php отсутствует — скопируйте config/db.php.example');

        $command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/migrate.php');
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('Не удалось применить миграции (шаг неуспешен): ' . implode("\n", $output));
        }

        self::$factory = PdoFactory::fromConfigFile(self::$configPath);
        self::$pdo = self::$factory->create();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::cleanupFixture();
        self::insertFixture();
    }

    protected function tearDown(): void
    {
        self::cleanupFixture();
        parent::tearDown();
    }

    public function testFindAllByAdmissionYearReturnsExpectedStudents(): void
    {
        $repository = new PdoStudentRepository(self::$factory);
        $students = $repository->findAllByAdmissionYear(self::ADMISSION_YEAR);

        self::assertCount(2, $students);
        $ids = array_map(static fn (Student $s): int => $s->id, $students);
        self::assertSame([self::$studentIds[0], self::$studentIds[1]], $ids);

        $first = $students[0];
        self::assertFalse($first->isTargetQuota);
        self::assertFalse($first->isPaid);
        self::assertFalse($first->isDisabled);
        self::assertSame(290, $first->entranceExamsSum);
        self::assertEqualsWithDelta(4.9, $first->gpaSem12, 0.0001);
        self::assertEqualsWithDelta(4.5, $first->gpaBasic, 0.0001);
        self::assertSame(95, $first->entranceTestScore);
        self::assertSame(self::$groupId, $first->groupId);

        $second = $students[1];
        self::assertTrue($second->isPaid);
        self::assertSame(self::$groupId, $second->groupId);

        // Студенты других годов приёма в выборку не попадают.
        $otherYear = $repository->findAllByAdmissionYear(self::ADMISSION_YEAR_OTHER);
        self::assertCount(1, $otherYear);
        self::assertSame(self::$groupId2024, $otherYear[0]->groupId);
    }

    public function testFindAllModulesBuildsModulesWithDisciplines(): void
    {
        $repository = new PdoModuleRepository(self::$factory);
        $modules = $repository->findAll();

        $module = self::findModuleById($modules, self::$moduleIds[0]);
        self::assertSame('QA-модуль МДС технический', $module->title);
        self::assertSame(ModuleType::Technical, $module->moduleType);
        self::assertSame(self::$schoolId, $module->schoolId);
        self::assertSame(10, $module->minStudents);
        self::assertSame(60, $module->maxStudents);
        self::assertSame(self::ACADEMIC_YEAR, $module->academicYear->toString());

        self::assertCount(3, $module->disciplines);
        $semesters = array_map(
            static fn (Discipline $d): int => $d->semester->asInt(),
            $module->disciplines,
        );
        sort($semesters);
        self::assertSame([3, 4, 5], $semesters);
    }

    public function testFindAllApplicationsBuildsApplicationWithPriorities(): void
    {
        $repository = new PdoApplicationRepository(self::$factory);
        $applications = $repository->findAll();

        $application = null;
        foreach ($applications as $candidate) {
            if ($candidate->id === self::$applicationId) {
                $application = $candidate;
                break;
            }
        }
        self::assertNotNull($application);
        self::assertSame(self::$studentIds[0], $application->studentId);

        self::assertCount(3, $application->items);
        $priorities = array_map(
            static fn (\App\Domain\Model\ApplicationItem $item): int => $item->priority,
            $application->items,
        );
        // Непрерывный ряд {1..N} (контракт #2, R-12) — конструктор Application
        // бросит исключение при нарушении; тут подтверждаем значения.
        self::assertSame([1, 2, 3], $priorities);
        $moduleIds = array_map(
            static fn (\App\Domain\Model\ApplicationItem $item): int => $item->moduleId,
            $application->items,
        );
        self::assertSame(self::$moduleIds, $moduleIds);
    }

    public function testSaveAssignmentsPersistsFinalRows(): void
    {
        $repository = new PdoModuleRepository(self::$factory);

        $repository->saveAssignments(
            new Assignment(
                self::$studentIds[0],
                self::$moduleIds[0],
                RankingAlgorithm::Date,
                AssignmentSource::Competition,
                1,
            ),
            new Assignment(
                self::$studentIds[1],
                self::$moduleIds[1],
                RankingAlgorithm::Criteria,
                AssignmentSource::TargetQuota,
                null,
            ),
        );

        $stmt = self::$pdo->prepare(
            'SELECT student_id, module_id, algorithm, source, rank_position, assigned_at
             FROM assignments
             WHERE student_id IN (?, ?)
             ORDER BY student_id',
        );
        $stmt->execute([self::$studentIds[0], self::$studentIds[1]]);
        /** @var list<array<string, string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);

        $first = $rows[0];
        self::assertSame((string) self::$studentIds[0], (string) $first['student_id']);
        self::assertSame((string) self::$moduleIds[0], (string) $first['module_id']);
        self::assertSame('date', $first['algorithm']);
        self::assertSame('competition', $first['source']);
        self::assertSame('1', (string) $first['rank_position']);
        self::assertNotSame('', (string) $first['assigned_at']);

        $second = $rows[1];
        self::assertSame((string) self::$studentIds[1], (string) $second['student_id']);
        self::assertSame((string) self::$moduleIds[1], (string) $second['module_id']);
        self::assertSame('criteria', $second['algorithm']);
        self::assertSame('target_quota', $second['source']);
        self::assertNull($second['rank_position']);
        self::assertNotSame('', (string) $second['assigned_at']);
    }

    public function testSaveAssignmentsWithNoRowsIsNoop(): void
    {
        (new PdoModuleRepository(self::$factory))->saveAssignments();

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM assignments');
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        self::assertSame(0, $count);
    }

    /**
     * Ищет модуль по идентификатору в результатах findAll().
     *
     * @param list<Module> $modules
     */
    private static function findModuleById(array $modules, int $id): Module
    {
        foreach ($modules as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        self::fail('Модуль с id=' . $id . ' не найден в списке findAll()');
    }

    /** Удаляет фикстуру (включая следы прерванных прогонов) в порядке внешних ключей. */
    private static function cleanupFixture(): void
    {
        $studentIds = 'SELECT s.id FROM students s '
            . 'JOIN student_groups g ON g.id = s.group_id '
            . 'JOIN schools sc ON sc.id = g.school_id '
            . 'WHERE sc.code = ?';
        $groupId = 'SELECT g.id FROM student_groups g '
            . 'JOIN schools sc ON sc.id = g.school_id '
            . 'WHERE sc.code = ?';

        self::execute(
            'DELETE FROM application_items WHERE application_id IN ('
            . 'SELECT a.id FROM applications a WHERE a.student_id IN (' . $studentIds . ')'
            . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM applications WHERE student_id IN (' . $studentIds . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM assignments WHERE student_id IN (' . $studentIds . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM disciplines WHERE module_id IN ('
            . 'SELECT m.id FROM modules m JOIN schools sc ON sc.id = m.school_id WHERE sc.code = ?'
            . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM modules WHERE school_id IN ('
            . 'SELECT sc.id FROM schools sc WHERE sc.code = ?'
            . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM students WHERE group_id IN (' . $groupId . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM student_groups WHERE school_id IN ('
            . 'SELECT sc.id FROM schools sc WHERE sc.code = ?'
            . ')',
            [self::SCHOOL_CODE],
        );
        self::execute(
            'DELETE FROM schools WHERE code = ?',
            [self::SCHOOL_CODE],
        );
    }

    /** Вставляет фикстуру и сохраняет идентификаторы созданных строк. */
    private static function insertFixture(): void
    {
        self::execute(
            'INSERT INTO schools (code, name) VALUES (?, ?)',
            [self::SCHOOL_CODE, self::SCHOOL_NAME],
        );
        self::$schoolId = (int) self::$pdo->lastInsertId();

        self::execute(
            'INSERT INTO student_groups (school_id, name, is_technical, admission_year) VALUES (?, ?, ?, ?)',
            [self::$schoolId, self::GROUP_NAME, 1, self::ADMISSION_YEAR],
        );
        self::$groupId = (int) self::$pdo->lastInsertId();

        self::execute(
            'INSERT INTO student_groups (school_id, name, is_technical, admission_year) VALUES (?, ?, ?, ?)',
            [self::$schoolId, self::GROUP_NAME_2024, 0, self::ADMISSION_YEAR_OTHER],
        );
        self::$groupId2024 = (int) self::$pdo->lastInsertId();

        self::$studentIds = [];
        $students = [
            ['QA-студент Иванов', 0, 0, 0, 290, 4.900, 4.500, 95],
            ['QA-студент Петрова', 0, 1, 0, 210, 3.600, 3.200, 70],
        ];
        foreach ($students as $student) {
            self::execute(
                'INSERT INTO students (
                    group_id, full_name, is_target_quota, is_paid, is_disabled,
                    entrance_exams_sum, gpa_sem12, gpa_basic, entrance_test_score
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    self::$groupId,
                    $student[0],
                    $student[1],
                    $student[2],
                    $student[3],
                    $student[4],
                    $student[5],
                    $student[6],
                    $student[7],
                ],
            );
            self::$studentIds[] = (int) self::$pdo->lastInsertId();
        }

        // Студент другого года приёма — контроль отрицательной выборки.
        self::execute(
            'INSERT INTO students (
                group_id, full_name, is_target_quota, is_paid, is_disabled,
                entrance_exams_sum, gpa_sem12, gpa_basic, entrance_test_score
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::$groupId2024, 'QA-стudent 2024', 0, 0, 0, 250, 4.000, 3.800, 80],
        );

        self::$moduleIds = [];
        $modules = [
            ['QA-модуль МДС технический', 'technical', 10, 60],
            ['QA-модуль МДС гуманитарный', 'humanitarian', 5, 40],
            ['QA-модуль МДС свободный', 'free', 3, 30],
        ];
        foreach ($modules as $module) {
            self::execute(
                'INSERT INTO modules (school_id, title, module_type, min_students, max_students, academic_year)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [self::$schoolId, $module[0], $module[1], $module[2], $module[3], self::ACADEMIC_YEAR],
            );
            self::$moduleIds[] = (int) self::$pdo->lastInsertId();

            $moduleId = self::$moduleIds[count(self::$moduleIds) - 1];
            foreach ([3, 4, 5] as $semester) {
                self::execute(
                    'INSERT INTO disciplines (module_id, title, semester) VALUES (?, ?, ?)',
                    [$moduleId, 'QA-дисциплина ' . $semester, $semester],
                );
            }
        }

        self::execute(
            'INSERT INTO applications (student_id, submitted_at) VALUES (?, ?)',
            [self::$studentIds[0], '2025-09-01 09:30:00'],
        );
        self::$applicationId = (int) self::$pdo->lastInsertId();

        foreach ([0 => 1, 1 => 2, 2 => 3] as $index => $priority) {
            self::execute(
                'INSERT INTO application_items (application_id, module_id, priority) VALUES (?, ?, ?)',
                [self::$applicationId, self::$moduleIds[$index], $priority],
            );
        }
    }

    /** @param list<mixed> $params */
    private static function execute(string $sql, array $params): void
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
    }
}