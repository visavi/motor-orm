<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Comment
 *
 * @property int $id
 * @property int $story_id
 * @property string $text
 */
class Comment extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/comments.csv';
}
