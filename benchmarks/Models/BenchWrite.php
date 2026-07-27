<?php

declare(strict_types=1);

namespace MotorORM\Benchmarks\Models;

use MotorORM\Builder;

/**
 * A scratch copy of the bench table, every write case works on it
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property int $time
 */
class BenchWrite extends Builder
{
    public string $table = __DIR__ . '/../data/bench_write.csv';
}
