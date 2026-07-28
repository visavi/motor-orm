<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Columns intentionally collide with builder method names
 *
 * @property int $id
 * @property string $count
 * @property string $first
 */
class Reserved extends Model
{
    public string $table = __DIR__ . '/../../tests/data/reserved.csv';
}
