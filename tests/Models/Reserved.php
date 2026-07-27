<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Columns intentionally collide with builder method names
 *
 * @property int $id
 * @property int $count
 * @property string $first
 */
class Reserved extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/reserved.csv';
}
