<?php

namespace MotorORM\Tests;

use MotorORM\Collection;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\User;
use MotorORM\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(Builder::class)]
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
     * Eager loading an undefined relation
     *
     */
    public function testWithUndefinedRelation(): void
    {
        $this->expectException(RuntimeException::class);

        Story::query()->with('undefined')->get();
    }
}
