<?php

declare(strict_types=1);

/**
 * Веб-точка входа B4-04/B4-06/B4-05: минимальная страница полного цикла распределения.
 *
 * Связка (glue) веб-контура: читает конфиги и каталоги из БД (только чтение —
 * решение §2.5 plan-B4-04), выбирает источник заявок по radio `source` из формы
 * (файл → WebWorkflow::run, БД → WebWorkflow::runWithApplications, B4-06), а также
 * алгоритм (date|criteria) и формат экспорта (csv|tsv) из формы (B4-05), выполняет
 * конвейер {@see \App\Ui\Web\WebWorkflow} и отдаёт ZIP-архив отчётных файлов
 * (var/export/) либо страницу с сообщением.
 *
 * Структура повторяет bin/console (B3-05) и сырой PDO для групп/карты школ
 * (паттерн DistributeCommand); унификация бутстрапа CLI/веба — контур экспорта
 * (тот же ExporterFactory + SchoolReportAssembler, что в DistributeCommand, B4-05).
 * Без фреймворков и авторизации (stack/client.md: «минимальный веб»); безопасность
 * вывода — htmlspecialchars; контент файла валидирует импортёр (B4-03).
 *
 * Запуск из контейнера: make web (php -S 0.0.0.0:8080 -t public).
 */

use App\Domain\Enum\ExportFormat;
use App\Domain\ValueObject\RatingWeights;
use App\Infrastructure\Db\PdoApplicationRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Db\PdoModuleRepository;
use App\Infrastructure\Db\PdoStudentRepository;
use App\Infrastructure\File\ImportException;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Ui\Web\WebWorkflow;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$pdoFactory = PdoFactory::fromConfigFile($root . '/config/db.php');
$pdo = $pdoFactory->create();

/** @var array{weights: array<string, float>, bonuses: array<string, float>, tiers: array<string, bool>, normalization: string} $ratingConfig */
$ratingConfig = require $root . '/config/ranking.php';
$ratingWeights = new RatingWeights(
    $ratingConfig['weights'],
    $ratingConfig['bonuses'],
    $ratingConfig['tiers'],
    $ratingConfig['normalization'],
);

/** @var array{target_quota_no_application_strategy: string} $distributionConfig */
$distributionConfig = require $root . '/config/distribution.php';

/** @var array{format: string, directory: string} $exportConfig */
$exportConfig = require $root . '/config/export.php';

// Каталоги/конфиги читаются один раз; сам WebWorkflow строится в handlePost()
// с выбранными из формы algorithm/format (B4-05) — конвейер не хранит выбор.
$catalog = [
    'students' => array_values((new PdoStudentRepository($pdoFactory))->findAllByAdmissionYear(SeedCatalog::ADMISSION_YEAR)),
    'modules' => array_values((new PdoModuleRepository($pdoFactory))->findAll()),
    'groups' => readGroups($pdo),
    'moduleEligibleSchools' => readEligibleSchools($pdo),
    'schoolCodes' => readSchoolCodes($pdo),
];

$webConfig = [
    'root' => $root,
    'ratingWeights' => $ratingWeights,
    'targetQuotaNoApplicationStrategy' => $distributionConfig['target_quota_no_application_strategy'],
    'exportDirectory' => $root . DIRECTORY_SEPARATOR . $exportConfig['directory'],
    'defaultFormat' => $exportConfig['format'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($catalog, $webConfig, $pdoFactory);
    // handlePost() завершает ответ (скачивание ZIP) либо возвращает управление для формы.
}

renderPage();

/**
 * Обрабатывает POST: источник заявок (radio source=file|db, B4-06) + выбор
 * алгоритма/формата (B4-05), запуск конвейера, отдача архива.
 *
 * При успехе отправляет ZIP и завершает скрипт; при блокировке/ошибке — рендерит
 * страницу с сообщением и завершает контролируемо.
 *
 * @param array<string, mixed> $catalog   каталоги/конфиги, прочитанные один раз
 *                                        ({students, modules, groups, moduleEligibleSchools, schoolCodes})
 * @param array<string, mixed> $webConfig связка (root, ratingWeights, targetQuotaNoApplicationStrategy,
 *                                        exportDirectory, defaultFormat)
 */
function handlePost(array $catalog, array $webConfig, \App\Infrastructure\Db\PdoFactory $pdoFactory): void
{
    $source = (string) ($_POST['source'] ?? '');
    $message = '';
    $result = null;
    $sourceLabel = $source === 'db' ? 'заявки из БД' : 'файл';
    $algorithm = (string) ($_POST['algorithm'] ?? WebWorkflow::DEFAULT_ALGORITHM);
    $formatValue = (string) ($_POST['format'] ?? $webConfig['defaultFormat']);

    try {
        $format = ExportFormat::tryFrom($formatValue);
        if ($format === null) {
            throw new \RuntimeException('Неизвестный формат экспорта: ' . $formatValue);
        }
        $workflow = new WebWorkflow(
            students: $catalog['students'],
            modules: $catalog['modules'],
            groups: $catalog['groups'],
            moduleEligibleSchools: $catalog['moduleEligibleSchools'],
            schoolCodes: $catalog['schoolCodes'],
            ratingWeights: $webConfig['ratingWeights'],
            algorithm: $algorithm,
            targetQuotaNoApplicationStrategy: $webConfig['targetQuotaNoApplicationStrategy'],
            format: $format,
            exportDirectory: $webConfig['exportDirectory'],
        );

        if ($source === 'file') {
            $file = $_FILES['applications'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Прикрепите файл заявок (CSV/TSV).');
            }
            $tmpPath = (string) $file['tmp_name'];
            $name = (string) $file['name'];
            $sourceLabel = 'файл ' . $name;
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'tsv'], true)) {
                throw new \RuntimeException('Допустимые расширения файла: .csv или .tsv (получено: .' . $extension . ').');
            }
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                throw new \RuntimeException('Загруженный файл повреждён — повторите отправку.');
            }

            $result = $workflow->run($tmpPath);
        } elseif ($source === 'db') {
            $applications = (new PdoApplicationRepository($pdoFactory))->findAll();
            $sourceLabel = 'заявки из БД (' . count($applications) . ')';
            $result = $workflow->runWithApplications($applications);
        } else {
            throw new \RuntimeException('Выберите источник заявок: файл или база данных.');
        }
    } catch (ImportException $exception) {
        $message = $exception->getMessage();
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    if ($result !== null && $result->archiveBytes !== null) {
        sendArchive((string) $result->archiveName, $result->archiveBytes);
    }

    renderPage($message, $result, $source, $sourceLabel, $algorithm, $formatValue);
}

/**
 * Отдаёт ZIP-архив с заголовками для скачивания и завершает скрипт.
 */
function sendArchive(string $archiveName, string $bytes): void
{
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $archiveName . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/**
 * Рендерит страницу: форма (radio источник заявок + выбор алгоритма/формата B4-05)
 * + результат последнего запуска.
 *
 * @param string      $error        сообщение об ошибке блокировки
 * @param \App\Ui\Web\WebResult|null $result результат последнего конвейера
 * @param string      $source       выбранный источник (file|db)
 * @param string      $sourceLabel  читаемая подпись источника («файл <имя>» / «заявки из БД»)
 * @param string      $algorithm    выбранный алгоритм (date|criteria)
 * @param string      $formatValue  выбранный формат (csv|tsv)
 */
function renderPage(
    string $error = '',
    ?\App\Ui\Web\WebResult $result = null,
    string $source = 'file',
    string $sourceLabel = 'файл',
    string $algorithm = 'date',
    string $formatValue = 'csv',
): void {
    $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $notice = '';
    if ($error !== '') {
        $notice = '<p class="error">' . $esc($error) . '</p>';
    } elseif ($result !== null) {
        $parts = [];
        if ($result->errors !== []) {
            $parts[] = implode('<br>', array_map($esc, $result->errors));
        }
        if ($result->validationErrors !== []) {
            $parts[] = implode('<br>', array_map($esc, $result->validationErrors));
        }
        if ($parts !== []) {
            $notice = '<div class="error"><p>Обработка заблокирована:</p><ul>' . implode(
                '',
                array_map(fn (string $item): string => '<li>' . $item . '</li>', $parts),
            ) . '</ul></div>';
        } else {
            $fileList = implode(
                '<br>',
                array_map(fn (string $path): string => $esc($path), $result->exportFilePaths),
            );
            $notice = '<div class="ok"><p>Источник: ' . $esc($sourceLabel) . '. Алгоритм: '
                . $esc($algorithm) . '. Формат: ' . $esc($formatValue) . '. Распределено студентов: '
                . $result->assignedCount . ' / '
                . $result->totalStudents . ' (R-11). Скачайте архив: '
                . '<strong>' . $esc((string) $result->archiveName) . '</strong>.</p>'
                . '<p class="files">Отчётные файлы (var/export/):<br>' . $fileList . '</p></div>';
        }
    }

    $fileChecked = $source === 'file' ? ' checked' : '';
    $dbChecked = $source === 'db' ? ' checked' : '';
    $dateSelected = $algorithm === 'date' ? ' selected' : '';
    $criteriaSelected = $algorithm === 'criteria' ? ' selected' : '';
    $csvSelected = $formatValue === 'csv' ? ' selected' : '';
    $tsvSelected = $formatValue === 'tsv' ? ' selected' : '';

    echo '<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Распределение студентов на модули МДС</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 40rem; padding: 0 1rem; color: #222; }
  h1 { font-size: 1.25rem; }
  label { display: block; margin-bottom: .5rem; }
  input[type="file"] { display: block; margin-bottom: .75rem; }
  input[type="radio"] { margin-right: .35rem; vertical-align: middle; }
  fieldset { border: 1px solid #ccc; border-radius: .25rem; margin-bottom: 1rem; padding: .75rem 1rem; }
  legend { font-weight: bold; padding: 0 .35rem; }
  select { display: block; margin-bottom: .75rem; }
  button { padding: .4rem .8rem; }
  .error { color: #b00020; }
  .ok { color: #15632a; }
  .files { font-size: .8rem; color: #555; word-break: break-all; }
  .hint { font-size: .8rem; color: #555; }
</style>
</head>
<body>
<h1>Распределение студентов на модули МДС</h1>
<form method="post" enctype="multipart/form-data">
  <fieldset>
    <legend>Параметры распределения</legend>
    <label for="algorithm">Алгоритм ранжирования</label>
    <select id="algorithm" name="algorithm">
      <option value="date"' . $dateSelected . '>По дате подачи (date)</option>
      <option value="criteria"' . $criteriaSelected . '>По критериям (criteria)</option>
    </select>
    <label for="format">Формат отчётных файлов</label>
    <select id="format" name="format">
      <option value="csv"' . $csvSelected . '>CSV «;» (utf-8)</option>
      <option value="tsv"' . $tsvSelected . '>TSV «tab»</option>
    </select>
    <p class="hint">Алгоритм — R-17; формат — ADR-003. Выбор применяется и к CLI (--algorithm/--format, B4-05).</p>
  </fieldset>
  <fieldset>
    <legend>Источник заявок</legend>
    <label for="source-file">
      <input type="radio" id="source-file" name="source" value="file" required' . $fileChecked . '>
      Файл заявок (CSV «;» или TSV «tab», колонки student_id;module_id;priority)
    </label>
    <input type="file" id="applications" name="applications" accept=".csv,.tsv">
    <label for="source-db">
      <input type="radio" id="source-db" name="source" value="db"' . $dbChecked . '>
      Заявки из базы данных
    </label>
    <p class="hint">Выберите «Файл заявок», чтобы загрузить файл (B4-03), или «Заявки из базы данных» — будут взяты заявки из applications (B4-06).</p>
  </fieldset>
  <button type="submit">Запустить распределение</button>
</form>
' . $notice . '
<p class="hint">По умолчанию: алгоритм date, формат csv (config/export.php).</p>
</body>
</html>';
}

/**
 * Группы (student_groups) — вне контрактов, сырой PDO (паттерн DistributeCommand).
 *
 * @param \PDO $pdo
 *
 * @return list<\App\Domain\Model\StudentGroup>
 */
function readGroups(\PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT id, school_id, name, is_technical, admission_year FROM student_groups ORDER BY id',
    );
    $stmt->execute();
    /** @var list<array{id: int|string, school_id: int|string, name: string|null, is_technical: int|string, admission_year: int|string}> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $row) {
        $groups[] = new \App\Domain\Model\StudentGroup(
            (int) $row['id'],
            (int) $row['school_id'],
            (string) $row['name'],
            (int) $row['is_technical'] === 1,
            (int) $row['admission_year'],
        );
    }

    return $groups;
}

/**
 * Карта «moduleId → школы» для свободных модулей (R-19) — сырой PDO.
 *
 * @param \PDO $pdo
 *
 * @return array<int, list<int>>
 */
function readEligibleSchools(\PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT module_id, school_id FROM module_eligible_schools ORDER BY module_id, school_id',
    );
    $stmt->execute();
    /** @var list<array{module_id: int|string, school_id: int|string}> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['module_id']][] = (int) $row['school_id'];
    }

    return $map;
}

/**
 * Коды школ (справочник `schools.code`) для имён отчётных файлов (ADR-001/ADR-003).
 *
 * @param \PDO $pdo
 *
 * @return array<int, string> schoolId → код
 */
function readSchoolCodes(\PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, code FROM schools ORDER BY id');
    $stmt->execute();
    /** @var list<array{id: int|string, code: string|null}> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $codes = [];
    foreach ($rows as $row) {
        $codes[(int) $row['id']] = (string) $row['code'];
    }

    return $codes;
}