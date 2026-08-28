<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Tests\Records\AuthorRecord;

/**
 * The users table, read into a record of its own
 *
 * @property int $id
 * @property string $login
 */
class Author extends Model
{
    public string $table = __DIR__ . '/../../tests/data/users.csv';

    protected string $record = AuthorRecord::class;
}
