<?php

/**
 * The fixture table both benchmarks read.
 *
 * Kept apart so that a table generated once is measured by either of them
 * without being written again.
 */

declare(strict_types=1);

use MotorORM\Model;

const DATA_DIR    = __DIR__ . '/data';
const TABLE       = DATA_DIR . '/bench.csv';
const WRITE_TABLE = DATA_DIR . '/bench_write.csv';

/**
 * Multibyte aware padding
 */
function pad(string $value, int $width, int $type = STR_PAD_RIGHT): string
{
    $padding = str_repeat(' ', max(0, $width - mb_strlen($value)));

    return $type === STR_PAD_LEFT ? $padding . $value : $value . $padding;
}

/**
 * Build the fixture table, reusing it when the row count already matches
 */
function generateTable(int $rows): void
{
    if (! is_dir(DATA_DIR) && ! mkdir(DATA_DIR, 0777, true) && ! is_dir(DATA_DIR)) {
        fwrite(STDERR, 'Не удалось создать каталог ' . DATA_DIR . PHP_EOL);
        exit(1);
    }

    if (is_file(TABLE) && countLines(TABLE) - 1 === $rows) {
        return;
    }

    printf('Генерирую таблицу на %s строк...%s', number_format($rows, 0, '.', ' '), PHP_EOL);

    $file = new SplFileObject(TABLE, 'wb');
    $file->setCsvControl(...Model::CSV_CONTROL);
    $file->fputcsv(['id', 'name', 'title', 'text', 'time']);

    for ($i = 1; $i <= $rows; $i++) {
        $file->fputcsv([
            $i,
            'Имя' . ($i % 100),
            'Заголовок' . $i,
            'Текст текст текст',
            1231231234 + ($i % 1000),
        ]);
    }
}

/**
 * Count lines without loading the whole file
 */
function countLines(string $path): int
{
    $lines  = 0;
    $handle = fopen($path, 'rb');

    while (! feof($handle)) {
        $lines += substr_count((string) fread($handle, 1024 * 1024), "\n");
    }

    fclose($handle);

    return $lines;
}

/**
 * Highest primary key of the fixture, read straight from the tail of the file
 * so that measuring a case does not also measure this lookup
 */
function lastId(): int
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    $handle = fopen(TABLE, 'rb');
    fseek($handle, max(-4096, -filesize(TABLE)), SEEK_END);
    $tail = (string) fread($handle, 4096);
    fclose($handle);

    $lines = array_filter(explode("\n", $tail));

    return $id = (int) strtok((string) end($lines), ',');
}
