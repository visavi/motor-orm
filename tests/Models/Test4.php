<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Test4
 *
 * @property int column1
 * @property string column2
 * @property string column3
 * @property string column4
 */
class Test4 extends Builder
{
    public string $filePath = __DIR__ . '/../../tests/data/test4.csv';
}
