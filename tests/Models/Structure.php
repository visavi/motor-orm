<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Table whose columns the migration tests add, rename and drop
 *
 * @property string $column1
 * @property string $column2
 * @property string $column3
 */
class Structure extends Model
{
    public string $table = __DIR__ . '/../../tests/data/structures.csv';
}
