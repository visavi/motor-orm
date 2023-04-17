<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Test
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property int $time
 */
class Test extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/test.csv';
}
