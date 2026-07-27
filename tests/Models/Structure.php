<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Table whose columns the migration tests add, rename and drop
 *
 * @property int $column1
 * @property string $column2
 * @property string $column3
 */
class Structure extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/structures.csv';
}
