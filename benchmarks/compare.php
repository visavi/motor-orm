<?php

/**
 * The orm against the same work written in raw php.
 *
 * php benchmarks/compare.php
 * php benchmarks/compare.php --rows=200000 --runs=5
 *
 * The raw side is fopen + fgetcsv + array_combine in a loop: no objects, no
 * casts, no conditions to read, nothing but the file. It is the floor, and
 * what the orm costs is the distance to it.
 *
 * Every case runs in its own process, so the reported peak memory belongs to
 * that case alone.
 */

declare(strict_types=1);

use MotorORM\Benchmarks\Models\Bench;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/table.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MotorORM\\Benchmarks\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

$options = getopt('', ['rows::', 'runs::', 'case::', 'kind::', 'warm::']);
$rows    = (int) ($options['rows'] ?? 50000);
$runs    = max(1, (int) ($options['runs'] ?? 5));

/**
 * The table row by row, the way it would be read without an orm
 */
function rawRows(): Generator
{
    $handle = fopen(TABLE, 'rb');
    $header = fgetcsv($handle, escape: '');

    while (($row = fgetcsv($handle, escape: '')) !== false) {
        yield array_combine($header, $row);
    }

    fclose($handle);
}

$cases = [
    'найти запись по ключу' => [
        'raw' => static function () {
            $id = lastId();

            foreach (rawRows() as $row) {
                if ((int) $row['id'] === $id) {
                    return $row['title'];
                }
            }

            return null;
        },
        'orm' => static fn () => Bench::query()->find(lastId())->title,
    ],
    'посчитать строки по условию' => [
        'raw' => static function () {
            $count = 0;

            foreach (rawRows() as $row) {
                if ($row['name'] === 'Имя7') {
                    $count++;
                }
            }

            return $count . ' зап.';
        },
        'orm' => static fn () => Bench::query()->where('name', 'Имя7')->count() . ' зап.',
    ],
    'выбрать строки по условию' => [
        'raw' => static function () {
            $found = [];

            foreach (rawRows() as $row) {
                if ($row['name'] === 'Имя7') {
                    $found[] = $row;
                }
            }

            return count($found) . ' зап.';
        },
        'orm' => static fn () => count(Bench::query()->where('name', 'Имя7')->get()) . ' зап.',
    ],
    'страница из десяти строк' => [
        'raw' => static function () {
            $page = [];
            $seen = 0;

            foreach (rawRows() as $row) {
                if ($seen++ < 20) {
                    continue;
                }

                $page[] = $row;

                if (count($page) === 10) {
                    break;
                }
            }

            return count($page) . ' зап.';
        },
        'orm' => static fn () => count(Bench::query()->offset(20)->limit(10)->get()) . ' зап.',
    ],
    'десять последних с сортировкой' => [
        'raw' => static function () {
            $rows = [];

            foreach (rawRows() as $row) {
                $rows[] = $row;
            }

            usort($rows, static fn ($a, $b) => $b['id'] <=> $a['id']);

            return count(array_slice($rows, 0, 10)) . ' зап.';
        },
        'orm' => static fn () => count(Bench::query()->orderByDesc('id')->limit(10)->get()) . ' зап.',
    ],
    'обойти всю таблицу' => [
        'raw' => static function () {
            $length = 0;

            foreach (rawRows() as $row) {
                $length += strlen($row['title']);
            }

            return $length . ' байт';
        },
        'orm' => static function () {
            $length = 0;

            foreach (Bench::query()->cursor() as $record) {
                $length += strlen($record->title);
            }

            return $length . ' байт';
        },
    ],
    'прочитать таблицу целиком' => [
        'raw' => static function () {
            $rows = [];

            foreach (rawRows() as $row) {
                $rows[] = $row;
            }

            return count($rows) . ' зап.';
        },
        'orm' => static fn () => count(Bench::query()->get()) . ' зап.',
    ],
];

/* A single case, measured in a process of its own and reported as json */
if (isset($options['case'])) {
    $case = $cases[$options['case']][$options['kind']];

    /*
     * Timed warm, measured cold. Loading the classes a case needs costs about
     * a millisecond and is paid once per process, which on a case that touches
     * ten rows is most of what would be measured; a run before the timed one
     * takes it out. Memory has to be read the other way round, from a process
     * that has allocated nothing yet, or a freed result of the run before is
     * quietly reused and never shows
     */
    if (isset($options['warm'])) {
        $case();

        memory_reset_peak_usage();
    }

    $memory = memory_get_usage();
    $time   = hrtime(true);
    $result = $case();

    echo json_encode([
        'ms'     => (hrtime(true) - $time) / 1e6,
        'memory' => memory_get_peak_usage() - $memory,
        'result' => (string) $result,
    ], JSON_UNESCAPED_UNICODE);

    exit(0);
}

generateTable($rows);

printf(
    '%sТаблица: %s строк, %s   PHP %s   лучшее из %d прогонов%s%s',
    PHP_EOL,
    number_format($rows, 0, '.', ' '),
    number_format(filesize(TABLE) / 1048576, 1) . ' MB',
    PHP_VERSION,
    $runs,
    PHP_EOL,
    PHP_EOL
);

echo pad('операция', 32) . pad('raw php', 20, STR_PAD_LEFT) . pad('Motor ORM', 20, STR_PAD_LEFT)
    . pad('разница', 10, STR_PAD_LEFT) . PHP_EOL;
echo str_repeat('-', 82), PHP_EOL;

foreach ($cases as $name => $kinds) {
    $measured = [];

    foreach (array_keys($kinds) as $kind) {
        $best = INF;

        for ($run = 0; $run < $runs; $run++) {
            $data = runCase($name, $kind, true);

            if ($data === null) {
                $measured[$kind] = null;

                continue 2;
            }

            $best = min($best, $data['ms']);
        }

        $cold = runCase($name, $kind, false);

        $measured[$kind] = [$best, $cold['memory'] ?? 0];
    }

    if ($measured['raw'] === null || $measured['orm'] === null) {
        echo pad($name, 32) . pad('ошибка', 20, STR_PAD_LEFT) . pad('ошибка', 20, STR_PAD_LEFT)
            . pad('-', 10, STR_PAD_LEFT) . PHP_EOL;

        continue;
    }

    echo pad($name, 32)
        . pad(sprintf('%.1f ms %.1f MB', $measured['raw'][0], $measured['raw'][1] / 1048576), 20, STR_PAD_LEFT)
        . pad(sprintf('%.1f ms %.1f MB', $measured['orm'][0], $measured['orm'][1] / 1048576), 20, STR_PAD_LEFT)
        . pad(sprintf('%.2fx', $measured['orm'][0] / $measured['raw'][0]), 10, STR_PAD_LEFT)
        . PHP_EOL;
}

echo PHP_EOL;

/**
 * Run one case in a process of its own
 *
 * @return array|null null when the case did not survive the run
 */
function runCase(string $name, string $kind, bool $warm): ?array
{
    $output = shell_exec(sprintf(
        '%s %s --case=%s --kind=%s%s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        escapeshellarg($name),
        escapeshellarg($kind),
        $warm ? ' --warm=1' : ''
    ));

    $data = json_decode((string) $output, true);

    return is_array($data) ? $data : null;
}
