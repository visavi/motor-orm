<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Writable table, the CRUD tests empty it before every case
 *
 * @property int $id
 * @property string $name
 * @property string $value
 */
class Item extends Model
{
    public string $table = __DIR__ . '/../../tests/data/items.csv';
}
