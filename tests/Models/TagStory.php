<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Class TagStory
 *
 * @property int $id
 * @property string $tag_id
 * @property string $story_id
 */
class TagStory extends Model
{
    public string $table = __DIR__ . '/../../tests/data/tag_stories.csv';
}
