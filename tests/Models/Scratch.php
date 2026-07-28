<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Table with no file of its own, created and dropped inside the tests
 */
class Scratch extends Model
{
    public string $table = __DIR__ . '/../../tests/data/scratch.csv';
}
