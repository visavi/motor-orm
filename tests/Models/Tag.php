<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Tag
 *
 * @property int $id
 * @property string $name
 */
class Tag extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/tags.csv';
}
