<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\File;

use App\Infrastructure\File\ZipArchiver;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты ZIP-архиватора (B4-04, план v2 §4): структурная валидация архива
 * (PKWARE STORE) и побайтовый round-trip содержимого. Парсинг сторонний не нужен:
 * структура (local header → данные → центральный каталог → EOCD) читается вручную
 * через unpack — гарантирует, что архив распакуется внешними инструментами
 * (Windows Explorer/WinRAR), включая имена в UTF-8 (general purpose bit 11).
 *
 * Второй кейс: пустой список файлов → \InvalidArgumentException (архив без записей
 * недопустим, условие plan-B4-04 §2.3).
 */
final class ZipArchiverTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sr-zip-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = scandir($this->workDir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    @unlink($this->workDir . DIRECTORY_SEPARATOR . $item);
                }
            }
        }
        @rmdir($this->workDir);
    }

    public function testArchiveRoundTripPreservesNamesAndContent(): void
    {
        $first = $this->workDir . DIRECTORY_SEPARATOR . 'очет_по_школе.csv';
        $second = $this->workDir . DIRECTORY_SEPARATOR . 'second.csv';
        $firstContent = "2025-2026;3;11;1001\r\n";
        $secondContent = '2025-2026;4;12;1002';
        file_put_contents($first, $firstContent);
        file_put_contents($second, $secondContent);

        $archiveBytes = (new ZipArchiver())->archive([$first, $second]);

        self::assertNotSame('', $archiveBytes);

        $eocd = $this->readEocd($archiveBytes);
        self::assertSame(0x06054b50, $eocd['signature']);
        self::assertSame(2, $eocd['totalEntries']);

        $names = [];
        $contents = [];
        $position = $eocd['cdOffset'];
        for ($entry = 0; $entry < $eocd['totalEntries']; ++$entry) {
            $header = $this->readCentralEntry($archiveBytes, $position);
            self::assertSame(0x02014b50, $header['signature']);
            self::assertSame(0, $header['method'], 'метод должен быть STORE (0)');
            self::assertNotSame(0, $header['flags'] & 0x0800, 'UTF-8 bit 11 должен быть выставлен');

            $names[] = substr($archiveBytes, $position + 46, $header['nlen']);

            $local = $this->readLocalEntry($archiveBytes, $header['localOffset']);
            self::assertSame(0x04034b50, $local['signature']);
            self::assertSame($header['csize'], $local['csize']);

            $payload = substr(
                $archiveBytes,
                $header['localOffset'] + 30 + $local['nlen'],
                $local['usize'],
            );
            self::assertSame($header['crc'], crc32($payload), 'CRC-32 записи совпадает с содержимым');
            $contents[] = $payload;

            $position += 46 + $header['nlen'] + $header['elen'] + $header['clen'];
        }

        self::assertSame(['очет_по_школе.csv', 'second.csv'], $names, 'имена записаны в порядке файлов (UTF-8)');
        self::assertSame([$firstContent, $secondContent], $contents, 'содержимое файлов сохранено байт-в-байт');
    }

    public function testArchiveEmptyInputRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('список файлов пуст');

        (new ZipArchiver())->archive([]);
    }

    /**
     * Читает EOCD (последние 22 байта архива).
     *
     * @return array{
     *     signature: int,
     *     disk: int,
     *     cdDisk: int,
     *     entriesDisk: int,
     *     totalEntries: int,
     *     cdSize: int,
     *     cdOffset: int,
     *     commentLen: int
     * }
     */
    private function readEocd(string $bytes): array
    {
        $raw = unpack(
            'Vsignature/vdisk/vcdDisk/ventriesDisk/vtotalEntries/VcdSize/VcdOffset/vcommentLen',
            substr($bytes, -22),
        );
        if ($raw === false) {
            throw new \RuntimeException('ZipArchiverTest: EOCD не прочитан (unpack вернул false)');
        }

        return [
            'signature' => $this->toInt($raw['signature']),
            'disk' => $this->toInt($raw['disk']),
            'cdDisk' => $this->toInt($raw['cdDisk']),
            'entriesDisk' => $this->toInt($raw['entriesDisk']),
            'totalEntries' => $this->toInt($raw['totalEntries']),
            'cdSize' => $this->toInt($raw['cdSize']),
            'cdOffset' => $this->toInt($raw['cdOffset']),
            'commentLen' => $this->toInt($raw['commentLen']),
        ];
    }

    /**
     * Читает заголовок записи центрального каталога (46 байт по смещению).
     *
     * @return array{
     *     signature: int,
     *     versionMade: int,
     *     versionNeed: int,
     *     flags: int,
     *     method: int,
     *     time: int,
     *     date: int,
     *     crc: int,
     *     csize: int,
     *     usize: int,
     *     nlen: int,
     *     elen: int,
     *     clen: int,
     *     disk: int,
     *     internal: int,
     *     external: int,
     *     localOffset: int
     * }
     */
    private function readCentralEntry(string $bytes, int $offset): array
    {
        $raw = unpack(
            'Vsignature/vversionMade/vversionNeed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vinternal/Vexternal/VlocalOffset',
            substr($bytes, $offset, 46),
        );
        if ($raw === false) {
            throw new \RuntimeException('ZipArchiverTest: запись центрального каталога не прочитана');
        }

        return [
            'signature' => $this->toInt($raw['signature']),
            'versionMade' => $this->toInt($raw['versionMade']),
            'versionNeed' => $this->toInt($raw['versionNeed']),
            'flags' => $this->toInt($raw['flags']),
            'method' => $this->toInt($raw['method']),
            'time' => $this->toInt($raw['time']),
            'date' => $this->toInt($raw['date']),
            'crc' => $this->toInt($raw['crc']),
            'csize' => $this->toInt($raw['csize']),
            'usize' => $this->toInt($raw['usize']),
            'nlen' => $this->toInt($raw['nlen']),
            'elen' => $this->toInt($raw['elen']),
            'clen' => $this->toInt($raw['clen']),
            'disk' => $this->toInt($raw['disk']),
            'internal' => $this->toInt($raw['internal']),
            'external' => $this->toInt($raw['external']),
            'localOffset' => $this->toInt($raw['localOffset']),
        ];
    }

    /**
     * Читает local file header (30 байт по смещению).
     *
     * @return array{
     *     signature: int,
     *     versionNeed: int,
     *     flags: int,
     *     method: int,
     *     time: int,
     *     date: int,
     *     crc: int,
     *     csize: int,
     *     usize: int,
     *     nlen: int,
     *     elen: int
     * }
     */
    private function readLocalEntry(string $bytes, int $offset): array
    {
        $raw = unpack(
            'Vsignature/vversionNeed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen',
            substr($bytes, $offset, 30),
        );
        if ($raw === false) {
            throw new \RuntimeException('ZipArchiverTest: local file header не прочитан');
        }

        return [
            'signature' => $this->toInt($raw['signature']),
            'versionNeed' => $this->toInt($raw['versionNeed']),
            'flags' => $this->toInt($raw['flags']),
            'method' => $this->toInt($raw['method']),
            'time' => $this->toInt($raw['time']),
            'date' => $this->toInt($raw['date']),
            'crc' => $this->toInt($raw['crc']),
            'csize' => $this->toInt($raw['csize']),
            'usize' => $this->toInt($raw['usize']),
            'nlen' => $this->toInt($raw['nlen']),
            'elen' => $this->toInt($raw['elen']),
        ];
    }

    /**
     * Приводит значение unpack-поля к int с рантайм-проверкой: PHPStan не знает
     * типов значений unpack (mixed), а каст mixed → int запрещён на уровне max.
     */
    private function toInt(mixed $value): int
    {
        $number = is_numeric($value) ? (int) $value : null;
        if ($number === null) {
            throw new \RuntimeException('ZipArchiverTest: ожидалось числовое значение unpack-поля');
        }

        return $number;
    }
}