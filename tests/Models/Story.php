<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Story
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 */
class Story extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/stories.csv';

    /**
     * User relation
     *
     * @return Builder
     */
    public function user(): Builder
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    /**
     * Comments relation
     *
     * @return Builder
     */
    public function comments(): Builder
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Tags relation
     *
     * @return Builder
     */
    public function tags(): Builder
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
