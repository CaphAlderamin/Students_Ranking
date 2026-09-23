<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

/**
 * Исключение импорта файла заявок (B4-03): файл недоступен или не читается.
 *
 * Ошибки *данных* файла (невалидные строки, отсутствующие колонки) в исключение
 * не превращаются — они накапливаются в `ImportedApplications::errors` (критерий
 * ветки «ошибки читаемы», корректные строки импортируются).
 */
final class ImportException extends \RuntimeException
{
}