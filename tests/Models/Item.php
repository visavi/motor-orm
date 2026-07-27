<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Writable table, the CRUD tests empty it before every case
 *
 * @property int $id
 * @property string $name
 * @property string $value
 */
class Item extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/items.csv';
}
