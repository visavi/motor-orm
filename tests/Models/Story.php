<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;
use MotorORM\Relation;

/**
 * Class Story
 *
 * @property int $id
 * @property string $user_id
 * @property string $title
 */
class Story extends Model
{
    public string $table = __DIR__ . '/../../tests/data/stories.csv';

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
        return $this->hasMany(Comment::class);
    }

    /**
     * Tags relation
     *
     * @return Relation
     */
    public function tags(): Relation
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
