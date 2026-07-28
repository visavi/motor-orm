<?php

namespace MotorORM\Tests;

use BadMethodCallException;
use InvalidArgumentException;
use MotorORM\Query;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\CastedEvent;
use MotorORM\Tests\Models\Event;
use MotorORM\Tests\Models\Payload;
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
                $query->where('name', 'Миша')->where('created_at', '2009-01-06 08:40:36');
            })
            ->get();

        $this->assertCount(2, $find);
        $this->assertEquals(1, $find[0]->id);
        $this->assertEquals(18, $find[1]->id);
    }

    /**
     * Wildcards say where the pattern may be extended
     *
     */
    public function testWhereLike(): void
    {
        $this->assertCount(11, Article::query()->whereLike('title', 'Заголовок1%')->get());
        $this->assertCount(1, Article::query()->whereLike('title', '%овок15')->get());
        $this->assertCount(1, Article::query()->whereLike('title', '%овок15%')->get());
    }

    /**
     * A pattern without wildcards matches the whole value, as sql like does
     *
     */
    public function testWhereLikeWithoutWildcardsIsExact(): void
    {
        $this->assertCount(0, Article::query()->whereLike('title', 'Заголовок')->get());
        $this->assertCount(1, Article::query()->whereLike('title', 'Заголовок15')->get());
    }

    /**
     * Matching ignores the case unless it is asked not to
     *
     */
    public function testWhereLikeCaseSensitive(): void
    {
        $this->assertCount(3, Article::query()->whereLike('name', 'миша')->get());
        $this->assertCount(0, Article::query()->whereLike('name', 'миша', caseSensitive: true)->get());
        $this->assertCount(3, Article::query()->whereLike('name', 'Миша', caseSensitive: true)->get());
    }

    /**
     * Not like condition
     *
     */
    public function testWhereNotLike(): void
    {
        $this->assertCount(9, Article::query()->whereNotLike('title', 'Заголовок1%')->get());
    }

    /**
     * Like as an alternative to what came before it
     *
     */
    public function testOrWhereLike(): void
    {
        $find = Article::query()
            ->where('id', 1)
            ->orWhereLike('title', '%овок15')
            ->get();

        $this->assertCount(2, $find);
        $this->assertEquals(1, $find[0]->id);
        $this->assertEquals(15, $find[1]->id);
    }

    /**
     * Not like as an alternative to what came before it
     *
     */
    public function testOrWhereNotLike(): void
    {
        $find = Article::query()
            ->where('id', 1)
            ->orWhereNotLike('title', 'Заголовок1%')
            ->get();

        $this->assertCount(10, $find);
    }

    /**
     * An operator that is not a comparison is a mistake, not a match
     *
     */
    public function testUnknownOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Article::query()->where('title', 'lke', 'Заголовок1');
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
     * The same conditions answer the same way however often they are asked
     *
     */
    public function testBuilderCanBeReused(): void
    {
        $query = Article::query()->where('name', 'Миша');

        $this->assertCount(3, $query->get());
        $this->assertCount(3, $query->get());
        $this->assertSame(3, $query->count());
        $this->assertEquals(10, $query->first()->id);
        $this->assertCount(3, $query->get());
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
        $this->assertSame('2026-07-28 13:15:00', $find->updated_at);
        $this->assertSame('42', $find->views);
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

        $this->assertSame(42, $find->views);

        /* The neighbouring dates were not declared, so they stay as they were read */
        $this->assertSame('2026-07-28 12:30:00', $find->created_at);
        $this->assertSame('2026-07-28 13:15:00', $find->updated_at);
    }

    /**
     * A column cast to an array is read back as one
     *
     */
    public function testArrayCast(): void
    {
        $this->assertSame(['tags' => ['a', 'b'], 'hits' => 3], Payload::query()->find(1)->meta);
    }

    /**
     * Json a column cannot hold is an error, not a null
     *
     */
    public function testBrokenJsonIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('meta');

        Payload::query()->find(2);
    }

    /**
     * An empty value is null whether or not a cast was declared
     *
     */
    public function testEmptyValueIsNull(): void
    {
        $find = CastedEvent::query()->find(2);

        $this->assertNull($find->views);
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
