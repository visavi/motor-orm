<?php

namespace MotorORM\Tests;

use InvalidArgumentException;
use MotorORM\Collection;
use MotorORM\Query;
use MotorORM\Relation;
use MotorORM\RelationType;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\User;
use RuntimeException;

final class RelationTest extends TestCase
{
    /**
     * A relation through nothing goes nowhere
     */
    public function testRelationThroughNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Relation(RelationType::HasManyThrough, Story::class);
    }

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
     * A record loads its relation for the result it was read with, whatever
     * the query has read since
     *
     */
    public function testRelationOfRecordFromAnEarlierRead(): void
    {
        $query   = Story::query();
        $stories = $query->get();

        /* The same builder reads again, and what it holds is no longer that result */
        $query->where('id', 3)->get();

        $this->assertCount(2, $stories[0]->comments);
        $this->assertEquals('Comment1', $stories[0]->comments[0]->text);
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
     * The conditions a relation was declared with narrow what it loads
     *
     */
    public function testConstrainedRelation(): void
    {
        $story = Story::query()->find(1);

        $this->assertCount(2, $story->comments);
        $this->assertCount(1, $story->laterComments);
        $this->assertEquals('Comment2', $story->laterComments[0]->text);
    }

    /**
     * A constrained relation eager loaded for a whole result
     *
     */
    public function testWithConstrainedRelation(): void
    {
        $stories = Story::query()->with('laterComments')->get();

        $this->assertCount(1, $stories[0]->laterComments);
        $this->assertEquals('Comment2', $stories[0]->laterComments[0]->text);
        $this->assertCount(1, $stories[1]->laterComments);
        $this->assertEquals('Comment3', $stories[1]->laterComments[0]->text);
        $this->assertCount(0, $stories[2]->laterComments);
    }

    /**
     * A relation is read for the whole result at once, so a limit in its
     * declaration limits that one read and not each record
     *
     */
    public function testConstraintLimitsTheWholeRead(): void
    {
        /* Read on its own, the limit is the record's own */
        $this->assertCount(1, Story::query()->find(1)->lastComment);

        $stories = Story::query()->with('lastComment')->get();

        /* Read for three stories at once, the one comment goes to whoever it belongs to */
        $this->assertCount(0, $stories[0]->lastComment);
        $this->assertCount(1, $stories[1]->lastComment);
        $this->assertEquals('Comment3', $stories[1]->lastComment[0]->text);
    }

    /**
     * A relation through an intermediate table is narrowed the same way
     *
     */
    public function testConstrainedRelationThrough(): void
    {
        $stories = Story::query()->with('namedTags')->get();

        $this->assertCount(1, $stories[0]->namedTags);
        $this->assertEquals('tag2', $stories[0]->namedTags[0]->name);
        $this->assertCount(0, $stories[1]->namedTags);
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

    /**
     * A relation narrowed where it is eager loaded
     *
     */
    public function testWithClosure(): void
    {
        $stories = Story::query()->with([
            'comments' => static fn (Query $query) => $query->where('id', '>', 1),
        ])->get();

        $this->assertCount(1, $stories[0]->comments);
        $this->assertEquals('Comment2', $stories[0]->comments[0]->text);
        $this->assertCount(1, $stories[1]->comments);
        $this->assertCount(0, $stories[2]->comments);
    }

    /**
     * The closure narrows what the declaration already narrowed
     *
     */
    public function testWithClosureOnTopOfConstraint(): void
    {
        $stories = Story::query()->with([
            'laterComments' => static fn (Query $query) => $query->where('id', '>', 2),
        ])->get();

        $this->assertCount(0, $stories[0]->laterComments);
        $this->assertCount(1, $stories[1]->laterComments);
        $this->assertEquals('Comment3', $stories[1]->laterComments[0]->text);
    }

    /**
     * Plain names and narrowed ones travel in the same list
     *
     */
    public function testWithClosureMixedWithNames(): void
    {
        $stories = Story::query()->with([
            'user',
            'comments' => static fn (Query $query) => $query->where('id', 1),
        ])->get();

        $this->assertEquals('admin', $stories[0]->user->login);
        $this->assertCount(1, $stories[0]->comments);
        $this->assertEquals('Comment1', $stories[0]->comments[0]->text);
    }

    /**
     * A relation through an intermediate table is narrowed the same way
     *
     */
    public function testWithClosureThrough(): void
    {
        $stories = Story::query()->with([
            'tags' => static fn (Query $query) => $query->where('name', 'tag2'),
        ])->get();

        $this->assertCount(1, $stories[0]->tags);
        $this->assertEquals('tag2', $stories[0]->tags[0]->name);
        $this->assertCount(0, $stories[1]->tags);
    }

    /**
     * A record read on its own is narrowed the same way
     *
     */
    public function testWithClosureOnFind(): void
    {
        $story = Story::query()->with([
            'comments' => static fn (Query $query) => $query->where('id', 2),
        ])->find(1);

        $this->assertCount(1, $story->comments);
        $this->assertEquals('Comment2', $story->comments[0]->text);
    }

    /**
     * A relation narrowed by something that is not a closure
     *
     */
    public function testWithNonClosure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Story::query()->with(['comments' => 'id > 1'])->get();
    }

    /**
     * A narrowed relation that is not declared
     *
     */
    public function testWithClosureUndefinedRelation(): void
    {
        $this->expectException(RuntimeException::class);

        Story::query()->with(['undefined' => static fn (Query $query) => $query])->get();
    }

    /**
     * A method the model inherits is no relation, whatever it returns
     *
     * Reading a property must never run something that only looks like a
     * relation from the outside
     */
    public function testInheritedMethodIsNoRelation(): void
    {
        $model = Story::query()->model();

        $this->assertFalse($model->isRelation('getTable'));
        $this->assertFalse($model->isRelation('nothingOfTheSort'));
        $this->assertTrue($model->isRelation('comments'));
    }
}
