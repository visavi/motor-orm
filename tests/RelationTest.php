<?php

namespace MotorORM\Tests;

use MotorORM\Collection;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\User;
use MotorORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(Query::class)]
final class RelationTest extends TestCase
{
    /**
     * Lazy loaded hasOne relation
     *
     */
    public function testHasOne(): void
    {
        $story = Story::query()->find(1);

        $this->assertEquals('admin', $story->user->login);
    }

    /**
     * Lazy loaded hasMany relation
     *
     */
    public function testHasMany(): void
    {
        $user = User::query()->find(1);

        $this->assertInstanceOf(Collection::class, $user->stories);
        $this->assertCount(2, $user->stories);
        $this->assertEquals('Story1', $user->stories[0]->title);
    }

    /**
     * Lazy loaded hasManyThrough relation
     *
     */
    public function testHasManyThrough(): void
    {
        $story = Story::query()->find(1);

        $this->assertInstanceOf(Collection::class, $story->tags);
        $this->assertCount(2, $story->tags);
        $this->assertEquals('tag1', $story->tags[0]->name);
        $this->assertEquals('tag2', $story->tags[1]->name);
    }

    /**
     * Eager loaded hasOne relation
     *
     */
    public function testWithHasOne(): void
    {
        $stories = Story::query()->with('user')->get();

        $this->assertCount(3, $stories);
        $this->assertEquals('admin', $stories[0]->user->login);
        $this->assertEquals('admin', $stories[1]->user->login);
        $this->assertEquals('user', $stories[2]->user->login);
    }

    /**
     * Eager loaded hasMany relation
     *
     */
    public function testWithHasMany(): void
    {
        $users = User::query()->with('stories')->get();

        $this->assertCount(2, $users);
        $this->assertInstanceOf(Collection::class, $users[0]->stories);
        $this->assertCount(2, $users[0]->stories);
        $this->assertCount(1, $users[1]->stories);
    }

    /**
     * Eager loaded hasManyThrough relation
     *
     */
    public function testWithHasManyThrough(): void
    {
        $stories = Story::query()->with('tags')->get();

        $this->assertCount(3, $stories);
        $this->assertInstanceOf(Collection::class, $stories[0]->tags);
        $this->assertCount(2, $stories[0]->tags);
        $this->assertEquals('tag1', $stories[0]->tags[0]->name);
        $this->assertCount(1, $stories[1]->tags);
        $this->assertEquals('tag3', $stories[1]->tags[0]->name);
        $this->assertCount(0, $stories[2]->tags);
    }

    /**
     * Whether a relation is already loaded
     *
     */
    public function testRelationLoaded(): void
    {
        $story = Story::query()->find(1);

        $this->assertFalse($story->relationLoaded('user'));

        $story->user;

        $this->assertTrue($story->relationLoaded('user'));
    }

    /**
     * Eager loading marks the relation as loaded
     *
     */
    public function testRelationLoadedAfterWith(): void
    {
        $story = Story::query()->with('user')->get()[0];

        $this->assertTrue($story->relationLoaded('user'));
        $this->assertFalse($story->relationLoaded('comments'));
    }

    /**
     * Touching a relation loads it for every record of the same result,
     * so that a loop does not scan the table once per record
     *
     */
    public function testLazyHasManyLoadsEverySibling(): void
    {
        $users = User::query()->get();

        $this->assertFalse($users[1]->relationLoaded('stories'));

        $users[0]->stories;

        $this->assertTrue($users[1]->relationLoaded('stories'));
        $this->assertCount(1, $users[1]->stories);
        $this->assertEquals('Story3', $users[1]->stories[0]->title);
    }

    /**
     * The same batching for a hasOne relation
     *
     */
    public function testLazyHasOneLoadsEverySibling(): void
    {
        $stories = Story::query()->get();

        $this->assertFalse($stories[2]->relationLoaded('user'));

        $stories[0]->user;

        $this->assertTrue($stories[2]->relationLoaded('user'));
        $this->assertEquals('user', $stories[2]->user->login);
    }

    /**
     * The same batching for a hasManyThrough relation
     *
     */
    public function testLazyHasManyThroughLoadsEverySibling(): void
    {
        $stories = Story::query()->get();

        $this->assertFalse($stories[1]->relationLoaded('tags'));

        $stories[0]->tags;

        $this->assertTrue($stories[1]->relationLoaded('tags'));
        $this->assertEquals('tag3', $stories[1]->tags[0]->name);
        $this->assertCount(0, $stories[2]->tags);
    }

    /**
     * A record read on its own has no siblings to batch with
     *
     */
    public function testLazyRelationOnASingleRecord(): void
    {
        $story = Story::query()->find(2);

        $this->assertEquals('admin', $story->user->login);
        $this->assertCount(1, $story->tags);
    }

    /**
     * A missing hasOne gives an empty model, both lazily and eagerly
     *
     */
    public function testMissingHasOne(): void
    {
        $orphan = Story::query()->find(3);
        $orphan->user_id = 999;

        $this->assertNull($orphan->user->login);
    }

    /**
     * Eager loading a single record read with first()
     *
     */
    public function testWithOnFirst(): void
    {
        $story = Story::query()->with('user')->first();

        $this->assertTrue($story->relationLoaded('user'));
        $this->assertEquals('admin', $story->user->login);
    }

    /**
     * Eager loading a single record read with find()
     *
     */
    public function testWithOnFind(): void
    {
        $story = Story::query()->with(['user', 'tags'])->find(1);

        $this->assertTrue($story->relationLoaded('user'));
        $this->assertTrue($story->relationLoaded('tags'));
        $this->assertCount(2, $story->tags);
    }

    /**
     * Re-reading the record reloads its relations too
     *
     */
    public function testWithOnFirstReloads(): void
    {
        $story = Story::query()->with('user')->find(2);
        $story->user_id = 2;

        $this->assertEquals('admin', $story->fresh()->user->login);
    }

    /**
     * Eager loading an undefined relation
     *
     */
    public function testWithUndefinedRelation(): void
    {
        $this->expectException(RuntimeException::class);

        Story::query()->with('undefined')->get();
    }
}
