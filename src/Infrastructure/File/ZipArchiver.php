<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

/**
 * Минимальный ZIP-архиватор без расширений (B4-04, план §2.3).
 *
 * В образе php:8.3-cli-alpine нет ext-zip/ext-zlib, а стек менять запрещено
 * («Меняет: —» §2.1), поэтому архив собирается на чистом PHP методом STORE
 * (без сжатия, index 0) по спецификации PKWARE: local file header + данные +
 * центральный каталог + EOCD. Содержимое отчётных файлов в байтах совпадает
 * с файлами в var/export/ (ADR-003) — lossless «упаковка в один файл».
 *
 * Имена файлов пишутся в UTF-8 с general purpose bit 11 (0x0800): Windows Explorer
 * и WinRAR корректно распаковывают имена с кириллицей. CRC-32 — штатная функция
 * crc32() (условие plan-B4-04 §2.3). Версия записи 2.0 достаточно для STORE+bit 11.
 *
 * Пустой список файлов — \InvalidArgumentException (архив без записей недопустим);
 * несуществующий/нечитаемый файл — \InvalidArgumentException/\RuntimeException.
 */
final class ZipArchiver
{
    private const int LOCAL_HEADER = 0x04034b50;
    private const int CENTRAL_HEADER = 0x02014b50;
    private const int END_HEADER = 0x06054b50;

    private const int METHOD_STORE = 0;
    private const int UTF8_NAME_FLAG = 0x0800;
    private const int VERSION_20 = 20;

    /**
     * Собирает ZIP-архив (STORE) из перечисленных файлов.
     *
     * @param list<string> $filePaths пути файлов; в архив попадают имена basename(),
     *                                порядок записей — порядок массива
     *
     * @return string бинарные байты ZIP-архива (для Content-Disposition)
     *
     * @throws \InvalidArgumentException при пустом списке или отсутствии файла
     * @throws \RuntimeException         при ошибке чтения файла
     */
    public function archive(array $filePaths): string
    {
        if ($filePaths === []) {
            throw new \InvalidArgumentException('ZipArchiver: список файлов пуст — архив без записей недопустим');
        }

        $localParts = [];
        $central = '';
        $offset = 0;

        foreach ($filePaths as $path) {
            if (!is_file($path)) {
                throw new \InvalidArgumentException('ZipArchiver: файл не найден: ' . $path);
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('ZipArchiver: не удалось прочитать файл: ' . $path);
            }

            $name = basename($path);
            $nameLength = strlen($name);
            $crc = crc32($content);
            $size = strlen($content);

            $localHeader = pack(
                'VvvvvvVVVvv',
                self::LOCAL_HEADER,
                self::VERSION_20,
                self::UTF8_NAME_FLAG,
                self::METHOD_STORE,
                0, // время модификации (DOS, нет данных)
                0, // дата модификации (DOS, нет данных)
                $crc,
                $size, // compressed size = size (STORE)
                $size, // uncompressed size
                $nameLength,
                0, // длина extra-поля
            );
            $localParts[] = $localHeader . $name . $content;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_HEADER,
                self::VERSION_20, // version made by
                self::VERSION_20, // version needed to extract
                self::UTF8_NAME_FLAG,
                self::METHOD_STORE,
                0, // время
                0, // дата
                $crc,
                $size,
                $size,
                $nameLength,
                0, // длина extra-поля
                0, // длина комментария
                0, // номер диска
                0, // внутренние атрибуты
                0, // внешние атрибуты (обычный файл)
                $offset, // смещение local file header
            ) . $name;

            $offset += strlen($localHeader) + $nameLength + $size;
        }

        $eocd = pack(
            'VvvvvVVv',
            self::END_HEADER,
            0, // номер диска
            0, // диск с началом каталога
            count($filePaths), // записей на диске
            count($filePaths), // всего записей
            strlen($central),
            $offset,
            0, // длина комментария
        );

        return implode('', $localParts) . $central . $eocd;
    }
}