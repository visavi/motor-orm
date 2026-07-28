<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;

/**
 * Table too large to hold, written by the test that needs it
 *
 * @property int    $id
 * @property string $title
 */
class Bulk extends Model
{
    public string $table = __DIR__ . '/../../tests/data/bulk.csv';
}
