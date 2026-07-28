<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Table with a string primary key
 *
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    public string $table = __DIR__ . '/../../tests/data/settings.csv';
}
