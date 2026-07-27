<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class TagStory
 *
 * @property int $id
 * @property int $tag_id
 * @property int $story_id
 */
class TagStory extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/tag_stories.csv';
}
