<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Table whose column names look like they carry types, but do not
 *
 * @property int $id
 * @property string $uuid_id
 * @property string $created_at
 * @property string $updated_at
 * @property string $views
 * @property string $title
 */
class Event extends Model
{
    public string $table = __DIR__ . '/../../tests/data/events.csv';
}
