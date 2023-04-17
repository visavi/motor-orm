<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Test5
 *
 * @property int column1
 * @property string column2
 * @property string column3
 * @property string column4
 */
class Test5 extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/test5.csv';
}
