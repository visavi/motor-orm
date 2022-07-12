<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Test3
 *
 * @property int $id
 * @property string $name
 * @property string $value
 */
class Test3 extends Builder
{
    public string $filePath = __DIR__ . '/../../tests/data/test3.csv';
}
