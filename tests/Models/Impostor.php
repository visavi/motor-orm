<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use stdClass;

/**
 * A model naming something that is not a record at all
 */
class Impostor extends Model
{
    public string $table = __DIR__ . '/../../tests/data/articles.csv';

    protected string $record = stdClass::class;
}
