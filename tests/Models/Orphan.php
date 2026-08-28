<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Relation;

/**
 * A table whose keys may be empty, and so read back as null
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $story_id
 */
class Orphan extends Model
{
    public string $table = __DIR__ . '/../../tests/data/orphans.csv';

    /**
     * User relation
     *
     * @return Relation
     */
    public function user(): Relation
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    /**
     * Comments relation
     *
     * @return Relation
     */
    public function comments(): Relation
    {
        return $this->hasMany(Comment::class, 'story_id', 'story_id');
    }

    /**
     * Tags relation
     *
     * @return Relation
     */
    public function tags(): Relation
    {
        return $this->hasManyThrough(Tag::class, TagStory::class, 'story_id', 'tag_id', 'story_id', 'id');
    }
}
