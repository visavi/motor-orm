<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Class Tag
 *
 * @property int $id
 * @property string $name
 */
class Tag extends Model
{
    public string $table = __DIR__ . '/../../tests/data/tags.csv';
}
