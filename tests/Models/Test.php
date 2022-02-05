<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;

/**
 * Class Test
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property int $time
 */
class Test extends Model
{
    public string $filePath = __DIR__ . '/../../tests/data/test.csv';
}
