<?php

namespace MotorORM\Tests;

use BadMethodCallException;
use InvalidArgumentException;
use MotorORM\Query;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\CastedEvent;
use MotorORM\Tests\Models\Event;
use MotorORM\Tests\Models\Setting;
use MotorORM\Tests\Models\StringKeyEvent;
use MotorORM\Tests\Models\TagStory;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

#[CoversClass(Query::class)]
final class QueryTest extends TestCase
{
    /**
     * Or where
     *
     */
    public function testOrWhere(): void
    {
        $find = Article::query()->where('id', 1)->orWhere('id', 2)->get();

        $this->assertCount(2, $find);
        $this->assertEquals(1, $find[0]->id);
        $this->assertEquals(2, $find[1]->id);
    }

    /**
     * Grouped conditions via closure
     *
     */
    public function testWhereClosure(): void
    {
        $find = Article::query()
            ->where('name', 'Миша')
            ->where(static function (Query $query) {
                $query->where('id', 10)->orWhere('id', 11);
            })
            ->get();

        $this->assertCount(2, $find);
        $this->assertEquals(10, $find[0]->id);
        $this->assertEquals(11, $find[1]->id);
    }

    /**
     * Grouped conditions via or where closure
     *
     */
    public function testOrWhereClosure(): void
    {
        $find = Article::query()
            ->where('id', 1)
            ->orWhere(static function (Query $query) {
                $query->where('name', 'Миша')->where('time', 1231231236);
            })
            ->get();

        $this->assertCount(2, $find);
        $this->assertEquals(1, $find[0]->id);
        $this->assertEquals(18, $find[1]->id);
    }

    /**
     * Like conditions
     *
     */
    public function testWhereLike(): void
    {
        $this->assertCount(11, Article::query()->where('title', 'like', 'Заголовок1%')->get());
        $this->assertCount(1, Article::query()->where('title', 'like', '%овок15')->get());
        $this->assertCount(1, Article::query()->where('title', 'like', '%овок15%')->get());
        $this->assertCount(20, Article::query()->where('title', 'like', 'Заголовок')->get());
    }

    /**
     * Not like condition
     *
     */
    public function testWhereNotLike(): void
    {
        $find = Article::query()->where('title', 'not_like', 'Заголовок1%')->get();

        $this->assertCount(9, $find);
    }

    /**
     * Case insensitive comparison
     *
     */
    public function testWhereLax(): void
    {
        $this->assertCount(3, Article::query()->where('name', 'lax', 'миша')->get());
        $this->assertCount(0, Article::query()->where('name', 'миша')->get());
    }

    /**
     * Where in with an empty set
     *
     */
    public function testWhereInEmpty(): void
    {
        $this->assertCount(0, Article::query()->whereIn('id', [])->get());
        $this->assertCount(20, Article::query()->whereNotIn('id', [])->get());
    }

    /**
     * Conditional query building
     *
     */
    public function testWhen(): void
    {
        $find = Article::query()
            ->when(true, static fn (Query $query) => $query->where('name', 'Миша'))
            ->when(false, static fn (Query $query) => $query->where('id', 999))
            ->get();

        $this->assertCount(3, $find);
    }

    /**
     * Conditional query building with a default callback
     *
     */
    public function testWhenDefault(): void
    {
        $find = Article::query()
            ->when(
                false,
                static fn (Query $query) => $query->where('id', 999),
                static fn (Query $query) => $query->where('name', 'Миша'),
            )
            ->get();

        $this->assertCount(3, $find);
    }

    /**
     * Scope without parameters
     *
     */
    public function testScope(): void
    {
        $find = Article::query()->misha()->get();

        $this->assertCount(3, $find);
    }

    /**
     * Scope with a parameter
     *
     */
    public function testScopeWithParameter(): void
    {
        $find = Article::query()->ofName('Миша')->get();

        $this->assertCount(3, $find);
    }

    /**
     * Undefined method
     *
     */
    public function testUndefinedMethod(): void
    {
        $this->expectException(BadMethodCallException::class);

        Article::query()->undefinedMethod();
    }

    /**
     * Casts
     *
     */
    public function testCasts(): void
    {
        $find = Setting::query()->find('key1');

        $this->assertIsString($find->key);
        $this->assertEquals('key1', $find->key);
    }

    /**
     * Nothing is cast because of how a column is named, whatever the
     * name suggests and whatever the value looks like
     *
     */
    public function testUndeclaredColumnsStayStrings(): void
    {
        $find = Event::query()->find(1);

        $this->assertSame('3f2a-9b', $find->uuid_id);
        $this->assertSame('2026-07-28 12:30:00', $find->created_at);
        $this->assertSame('1231231234', $find->updated_at);
    }

    /**
     * A numeric primary key is an int without being declared
     *
     */
    public function testNumericPrimaryKeyIsInt(): void
    {
        $this->assertSame(1, Event::query()->find(1)->id);
    }

    /**
     * A string primary key stays a string
     *
     */
    public function testStringPrimaryKeyStaysString(): void
    {
        $this->assertSame('key1', Setting::query()->find('key1')->key);
    }

    /**
     * A declared cast overrides what the primary key would get
     *
     */
    public function testDeclaredCastOnPrimaryKey(): void
    {
        $this->assertSame('1', StringKeyEvent::query()->find(1)->id);
    }

    /**
     * A declared cast is applied
     *
     */
    public function testDeclaredCast(): void
    {
        $find = CastedEvent::query()->find(1);

        $this->assertSame(1, $find->id);
        $this->assertSame(1231231234, $find->updated_at);
        $this->assertSame('2026-07-28 12:30:00', $find->created_at);
    }

    /**
     * An empty value is null whether or not a cast was declared
     *
     */
    public function testEmptyValueIsNull(): void
    {
        $find = CastedEvent::query()->find(2);

        $this->assertNull($find->updated_at);
        $this->assertNull($find->title);
    }

    /**
     * Re-reading a record drops the unsaved changes
     */
    public function testFirstRereadsTheRecord(): void
    {
        $find = Article::query()->find(1);
        $find->name = 'изменено';

        $this->assertEquals('Петя', $find->fresh()->name);
    }

    /**
     * Table and key names
     *
     */
    public function testNames(): void
    {
        $article = Article::query();

        $this->assertEquals('articles', $article->getTable());
        $this->assertEquals('id', $article->getPrimaryKey());
        $this->assertEquals('article_id', $article->getForeignKey());
        $this->assertStringEndsWith('/data/articles.csv', $article->getPath());
    }

    /**
     * A multi word model name turns into a snake case foreign key
     */
    public function testCompoundForeignKey(): void
    {
        $this->assertEquals('tag_story_id', TagStory::query()->getForeignKey());
    }

    /**
     * Negative limit
     *
     */
    public function testInvalidLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Article::query()->limit(-2);
    }

    /**
     * Negative offset
     *
     */
    public function testInvalidOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Article::query()->offset(-1);
    }

    /**
     * Filtering by an undefined column
     *
     */
    public function testUndefinedColumnInWhere(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Article::query()->where('undefined', 1)->get();
    }

    /**
     * Sorting by an undefined column
     *
     */
    public function testUndefinedColumnInOrderBy(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Article::query()->orderBy('undefined')->get();
    }
}
