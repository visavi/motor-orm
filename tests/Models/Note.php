<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Tests\Records\PublicationRecord;

/**
 * A table written by the tests, read into a record of its own
 *
 * @property int $id
 * @property string $title
 */
class Note extends Model
{
    public string $table = __DIR__ . '/../../tests/data/scratch.csv';

    protected string $record = PublicationRecord::class;
}
