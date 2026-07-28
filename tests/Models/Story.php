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

    /**
     * Comments past the first one, a relation narrowed by its declaration
     *
     * @return Relation
     */
    public function laterComments(): Relation
    {
        return $this->hasMany(Comment::class)->constrain(
            static fn (Query $query) => $query->where('id', '>', 1)
        );
    }

    /**
     * A relation whose declaration limits it
     *
     * @return Relation
     */
    public function lastComment(): Relation
    {
        return $this->hasMany(Comment::class)->constrain(
            static fn (Query $query) => $query->orderByDesc('id')->limit(1)
        );
    }

    /**
     * Tags of one name, narrowed through the intermediate table
     *
     * @return Relation
     */
    public function namedTags(): Relation
    {
        return $this->hasManyThrough(Tag::class, TagStory::class)->constrain(
            static fn (Query $query) => $query->where('name', 'tag2')
        );
    }
}
