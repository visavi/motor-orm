<?php

declare(strict_types=1);

namespace MotorORM\Benchmarks\Models;

use MotorORM\Builder;

/**
 * Class Bench
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property int $time
 */
class Bench extends Builder
{
    public string $table = __DIR__ . '/../data/bench.csv';
}
