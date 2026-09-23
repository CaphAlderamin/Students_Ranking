<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use League\Csv\Reader;

/**
 * Импортёр исходных данных заявок (B4-03, R-12) — альтернатива ручному вводу в веб-форме B4-04.
 *
 * Читает файл в конвенциях ADR-003 (CSV `;` с опциональным BOM UTF-8 / TSV `\t`) и
 * преобразует в Domain `Application` (B1-06). Заголовок идентифицирует колонки
 * (`student_id`, `module_id`, `priority`), порядок в файле не важен. Невалидная строка
 * не валит файл — фиксируется как «файл: строка N: причина», остальные строки читаются.
 *
 * Валидация: целочисленные id, приоритеты {1..3} (R-12), существование студента и модуля
 * через каталоги из конструктора (как SchoolReportAssembler, B4-02), отсутствие дублей
 * (student_id, priority)/(student_id, module_id), приоритеты заявки = непрерывный ряд
 * {1..N} (Р1 п. 6.2). Глубокие проверки R-13/окна подачи — потребитель (`ApplicationValidator`,
 * B2-04, B4-04).
 */
final readonly class ReportApplicationImporter implements ApplicationImporterInterface
{
    /** Обязательные колонки-идентификаторы (заголовок файла — идентификатор колонки). */
    private const array REQUIRED_HEADER = ['student_id', 'module_id', 'priority'];

    /** @var array<int, Module> каталог модулей: moduleId → Module */
    private array $modules;

    /** @var array<int, Student> каталог студентов: studentId → Student */
    private array $students;

    /**
     * @param array<int, Module>  $modules  каталог модулей (ключ — id модуля)
     * @param array<int, Student> $students каталог студентов (ключ — id студента)
     */
    public function __construct(array $modules, array $students)
    {
        $this->modules = $modules;
        $this->students = $students;
    }

    public function import(string $filePath): ImportedApplications
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new ImportException('ReportApplicationImporter: не удалось прочитать файл: ' . $filePath);
        }

        [$content, $delimiter] = self::normalize($content);
        if (trim($content) === '') {
            // Пустой файл / только перевод строки → ноль записей, без ошибок (решение §2.2).
            return new ImportedApplications([], []);
        }

        $reader = Reader::createFromString($content);
        $reader->setDelimiter($delimiter);

        $errors = [];
        $positions = [];
        $byStudent = [];
        $order = [];
        // Один «момент подачи» на весь импорт: приём файла ≈ окно подачи (R-12), решение §2.3.
        $submittedAt = new \DateTimeImmutable('now');

        $rowNumber = 1; // 1 — строка-заголовок; данные начинаются с 2.
        foreach ($reader->getRecords() as $record) {
            // Ячейки нормализуются (trim); отсутствующие хвостовые колонки — пустые.
            $cells = [];
            foreach ($record as $cell) {
                $cells[] = is_string($cell) ? trim($cell) : '';
            }

            // Первая запись — заголовок: обязательные колонки по именам, порядок не важен.
            if ($positions === []) {
                $missing = self::missingColumn($cells, $positions);
                if ($missing !== null) {
                    return new ImportedApplications([], [
                        'файл: заголовок: нет обязательной колонки "' . $missing . '"',
                    ]);
                }
                continue;
            }

            ++$rowNumber;
            $parsed = self::parseRow($rowNumber, $cells, $positions);
            if ($parsed['row'] === null) {
                $errors[] = $parsed['error'];
                continue;
            }
            [$studentId, $moduleId, $priority] = $parsed['row'];

            if (!isset($this->students[$studentId])) {
                $errors[] = 'файл: строка ' . $rowNumber . ': нет студента #' . $studentId;
                continue;
            }
            if (!isset($this->modules[$moduleId])) {
                $errors[] = 'файл: строка ' . $rowNumber . ': нет модуля #' . $moduleId;
                continue;
            }

            $seenPriority = $byStudent[$studentId]['seenPriority'] ?? [];
            $seenModule = $byStudent[$studentId]['seenModule'] ?? [];
            if (isset($seenPriority[$priority])) {
                $errors[] = 'файл: строка ' . $rowNumber . ': студент #' . $studentId
                    . ': приоритет ' . $priority . ' уже указан (строка ' . $seenPriority[$priority] . ')';
                continue;
            }
            if (isset($seenModule[$moduleId])) {
                $errors[] = 'файл: строка ' . $rowNumber . ': студент #' . $studentId
                    . ': модуль #' . $moduleId . ' уже указан (строка ' . $seenModule[$moduleId] . ')';
                continue;
            }

            if (!isset($byStudent[$studentId])) {
                $order[] = $studentId;
                $byStudent[$studentId] = [
                    'studentId' => $studentId,
                    'firstLine' => $rowNumber,
                    'seenPriority' => [],
                    'seenModule' => [],
                    'items' => [],
                ];
            }
            $byStudent[$studentId]['seenPriority'][$priority] = $rowNumber;
            $byStudent[$studentId]['seenModule'][$moduleId] = $rowNumber;
            $byStudent[$studentId]['items'][] = ['moduleId' => $moduleId, 'priority' => $priority];
        }

        $applications = [];
        $nextId = 1; // Синтетический id в пределах файла; реальный id даст БД на сохранении (B4-04).
        foreach ($order as $studentId) {
            $group = $byStudent[$studentId];

            // Диапазон и число приоритетов проверяются на уровне заявки (R-12: N ∈ 1..3);
            // группа заявки создаётся только при первой валидной строке студента,
            // поэтому здесь count ≥ 1 уже гарантирован парсером.
            $count = count($group['items']);
            if ($count > 3) {
                $errors[] = 'файл: строка ' . $group['firstLine'] . ': студент #' . $studentId
                    . ': приоритетов должно быть от 1 до 3 (R-12), получено: ' . $count;
                continue;
            }

            $priorities = array_column($group['items'], 'priority');
            sort($priorities);
            if ($priorities !== range(1, $count)) {
                // Выход за верхнюю границу 1..3 (R-12) отличаем от пропуска в середине
                // ряда: первое — «вне диапазона», второе — «не непрерывный ряд» (Р1 п. 6.2).
                $exceedsRange = $priorities[$count - 1] > 3;
                $errors[] = $exceedsRange
                    ? 'файл: строка ' . $group['firstLine'] . ': студент #' . $studentId
                        . ': приоритеты вне диапазона 1..3 (R-12): ' . implode(', ', $priorities)
                    : 'файл: строка ' . $group['firstLine'] . ': студент #' . $studentId
                        . ': приоритеты не образуют непрерывный ряд {1..' . $count . '}: ' . implode(', ', $priorities);
                continue;
            }

            // Детерминизм результата: элементы заявки — по возрастанию приоритета.
            usort($group['items'], static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);
            $items = array_map(
                static fn (array $item): ApplicationItem => new ApplicationItem($item['moduleId'], $item['priority']),
                $group['items'],
            );

            // ImportedApplication — внутренний этап «заявка как в файле»: держит инварианты
            // (1..3, непрерывность) между группировкой строк и сборкой Domain Application.
            $imported = new ImportedApplication($studentId, $submittedAt, $items);
            $applications[] = new Application($nextId++, $imported->studentId, $imported->submittedAt, $imported->items);
        }

        return new ImportedApplications($applications, $errors);
    }

    /**
     * Убирает BOM UTF-8 (сигнал CSV, ADR-003) и выбирает разделитель:
     * BOM → CSV `;`; иначе таб в первой строке → TSV `\t`; иначе CSV `;`.
     *
     * @return array{string, string} [нормализованное содержимое, разделитель]
     */
    private static function normalize(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return [substr($content, 3), ';'];
        }
        $firstLineEnd = strcspn($content, "\r\n");

        return [$content, str_contains(substr($content, 0, $firstLineEnd), "\t") ? "\t" : ';'];
    }

    /**
     * Проверяет присутствие обязательных колонок в заголовке и записывает их позиции.
     *
     * @param array<string>    $header    нормализованные ячейки заголовка
     * @param array<string, int> $positions результат: имя колонки → индекс в строке
     *
     * @return string|null имя отсутствующей обязательной колонки (null — все на месте)
     */
    private static function missingColumn(array $header, array &$positions): ?string
    {
        foreach (self::REQUIRED_HEADER as $column) {
            $index = array_search($column, $header, true);
            if ($index === false) {
                return $column;
            }
            $positions[$column] = (int) $index;
        }

        return null;
    }

    /**
     * Разбирает строку данных: непустые значения, целые id (≥ 1). Диапазон и число
     * приоритетов проверяются на уровне заявки (группы строк студента, R-12).
     *
     * @param array<string>    $cells     ячейки строки (нормализованные: trim)
     * @param array<string, int> $positions имя колонки → индекс (см. missingColumn)
     *
     * @return array{row: array{int, int, int}|null, error: string}
     *               `row` = [studentId, moduleId, priority] при успехе; при ошибке — null
     *               и `error` с читаемым сообщением «файл: строка N: причина»
     */
    private static function parseRow(int $rowNumber, array $cells, array $positions): array
    {
        $values = [];
        foreach (self::REQUIRED_HEADER as $column) {
            $raw = $cells[$positions[$column]] ?? null;
            if ($raw === null || $raw === '') {
                return ['row' => null, 'error' => 'файл: строка ' . $rowNumber . ': колонка "' . $column . '" пуста'];
            }
            if (preg_match('/^\d+$/', $raw) !== 1) {
                return ['row' => null, 'error' => 'файл: строка ' . $rowNumber
                    . ': значение "' . $raw . '" колонки "' . $column . '" не является целым числом'];
            }
            $values[$column] = (int) $raw;
        }

        if ($values['student_id'] < 1 || $values['module_id'] < 1) {
            return ['row' => null, 'error' => 'файл: строка ' . $rowNumber
                . ': student_id и module_id должны быть ≥ 1'];
        }

        return ['row' => [$values['student_id'], $values['module_id'], $values['priority']], 'error' => ''];
    }
}