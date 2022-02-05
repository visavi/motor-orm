<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;

/**
 * Class Test3
 *
 * @property int $id
 * @property string $name
 * @property string $value
 */
class Test3 extends Model
{
    public string $filePath = __DIR__ . '/../../tests/data/test3.csv';
}
