<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Class Comment
 *
 * @property int $id
 * @property string $story_id
 * @property string $text
 */
class Comment extends Model
{
    public string $table = __DIR__ . '/../../tests/data/comments.csv';
}
