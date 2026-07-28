<?php

namespace MotorORM\Tests;

use MotorORM\Collection;
use MotorORM\Pagination;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Setting;
use MotorORM\Tests\Models\Item;
use MotorORM\Tests\Models\Reserved;
use MotorORM\Tests\Models\Scratch;
use MotorORM\Tests\Models\Story;
use MotorORM\Query;
use MotorORM\SortOrder;
use MotorORM\Table;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

#[CoversClass(Query::class)]
final class QueryBuilderTest extends TestCase
{
    /**
     * Item is a writable fixture, every test starts with it empty
     */
    protected function setUp(): void
    {
        Item::query()->truncate();
    }

    /**
     * A value is read back as it was written, whatever it holds
     *
     * A backslash before the closing quote used to escape it, so a value
     * ending in one ran into the rows below and took them with it
     */
    public function testValuesSurviveTheirPunctuation(): void
    {
        $values = [
            'запятая'        => 'a,b',
            'кавычка'        => 'он сказал "да"',
            'перевод строки' => "первая\nвторая",
            'слеш внутри'    => 'путь\к\файлу',
            'слеш в конце'   => 'C:\каталог\\',
            'слеш и кавычка' => 'конец\"хвост',
            'два слеша'      => 'a\\\\b',
        ];

        foreach ($values as $name => $value) {
            Item::query()->create(['name' => $name, 'value' => $value]);
        }

        $read = Item::query()->get();

        $this->assertCount(count($values), $read);

        foreach (array_values($values) as $index => $value) {
            $this->assertSame($value, $read[$index]->value, $read[$index]->name);
        }
    }

    /**
     * Reading a property named like a method must not run that method
     */
    public function testReadingAMethodNameDoesNotRunIt(): void
    {
        Item::query()->create(['name' => 'one', 'value' => 'first']);
        Item::query()->create(['name' => 'two', 'value' => 'second']);

        $item = Item::query()->find(1);

        $this->assertNull($item->delete);
        $this->assertNull($item->truncate);
        $this->assertNull($item->headers);
        $this->assertCount(2, Item::query()->get());
    }

    /**
     * A relation declared on the model still resolves
     */
    public function testRelationStillResolves(): void
    {
        $this->assertEquals('admin', Story::query()->find(1)->user->login);
    }

    /**
     * Reading a table that does not exist is an error
     *
     * Building the query is not: nothing has been asked of the table yet
     */
    public function testQueryingAMissingTable(): void
    {
        @unlink((new Scratch())->getPath());

        $query = Scratch::query()->where('id', 1);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('does not exist');

        $query->get();
    }

    /**
     * Querying a table that does not exist must not create it
     */
    public function testQueryingAMissingTableDoesNotCreateIt(): void
    {
        $path = (new Scratch())->getPath();
        @unlink($path);

        try {
            Scratch::query()->count();
        } catch (UnexpectedValueException) {
            // the table is missing, that is the point of the test
        }

        $this->assertFileDoesNotExist($path);
    }

    /**
     * A write that fails halfway through leaves the table as it was
     */
    public function testFailedWriteLeavesTheTableIntact(): void
    {
        Item::query()->create(['name' => 'one', 'value' => 'a']);
        Item::query()->create(['name' => 'two', 'value' => 'b']);

        $path   = (new Item())->getPath();
        $before = file_get_contents($path);

        $written = 0;

        try {
            new Table(new Item())->rewrite(function (array $current, SplFileObject $target) use (&$written) {
                $target->fputcsv($current);

                /* Two rows are in the new file already when this blows up */
                if (++$written > 1) {
                    throw new RuntimeException('the write broke halfway through');
                }
            });
            $this->fail('the write was expected to fail');
        } catch (RuntimeException) {
            // that is the point of the test
        }

        $this->assertSame($before, file_get_contents($path));
        $this->assertCount(2, Item::query()->get());
    }

    /**
     * Walking the table one record at a time
     */
    public function testCursor(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        $names = [];
        foreach (Item::query()->where('id', '>', 3)->orderByDesc('id')->limit(2)->cursor() as $item) {
            $names[] = $item->name;
        }

        $this->assertSame(['name6', 'name5'], $names);
    }

    /**
     * The cursor yields nothing when nothing matches
     */
    public function testCursorEmpty(): void
    {
        $this->assertSame([], iterator_to_array(Item::query()->cursor()));
    }

    /**
     * Find by primary key
     */
    public function testFind(): void
    {
        $find = Article::query()->find(17);

        $this->assertIsObject($find);
        $this->assertEquals('17', $find->id);
    }

    /**
     * Find by primary key empty
     */
    public function testFindEmpty(): void
    {
        $find = Article::query()->find(777);

        $this->assertNull($find);
    }

    /**
     * Find by name limit 1
     */
    public function testWhereLimit(): void
    {
        $find = Article::query()->where('name', 'Миша')->limit(1)->get();

        $this->assertInstanceOf(Collection::class, $find);
        $this->assertIsObject($find[0]);
        //$this->assertClassHasProperty('attr', $find[0]);
        $this->assertEquals('Миша', $find[0]->name);
        $this->assertEquals('Заголовок10', $find[0]->title);
    }

    /**
     * Find by name and last 1
     *
     */
    public function testWhereLimitLast(): void
    {
        $find = Article::query()->where('name', 'Миша')->orderByDesc('id')->first();

        $this->assertIsObject($find);
        //$this->assertObjectHasAttribute('attr', $find);
        $this->assertEquals('Миша', $find->name);
        $this->assertEquals('Заголовок18', $find->title);
    }


    /**
     * Find by name and title
     *
     */
    public function testWhereWhereGet(): void
    {
        $find = Article::query()->where('name', 'Миша')->where('title', 'Заголовок10')->get();

        $this->assertInstanceOf(Collection::class, $find);
        $this->assertIsObject($find[0]);
        //$this->assertObjectHasAttribute('attr', $find[0]);
        $this->assertEquals('Миша', $find[0]->name);
        $this->assertEquals('Заголовок10', $find[0]->title);
    }

    /**
     * Find by condition
     *
     */
    public function testWhere(): void
    {
        $find = Article::query()->where('created_at', '>=', '2009-01-06 08:40:35')->get();

        $this->assertInstanceOf(Collection::class, $find);
        //$this->assertClassHasAttribute('elements', Collection::class);
        //$this->assertClassNotHasAttribute('paginator', Collection::class);
        $this->assertIsObject($find[0]);
        $this->assertCount(3, $find);
        //$this->assertObjectHasAttribute('attr', $find[0]);
        $this->assertGreaterThanOrEqual('2009-01-06 08:40:35', $find[0]->created_at);
        $this->assertGreaterThanOrEqual('2009-01-06 08:40:35', $find[1]->created_at);
        $this->assertGreaterThanOrEqual('2009-01-06 08:40:35', $find[2]->created_at);
    }

    /**
     * Find by condition
     *
     */
    public function testWherePaginate(): void
    {
        $find = Article::query()->where('created_at', '>=', '2009-01-06 08:40:35')->paginate(2);

        $this->assertInstanceOf(Pagination::class, $find);
        //$this->assertClassHasAttribute('elements', Pagination::class);
        //$this->assertClassHasAttribute('paginator', Pagination::class);
        $this->assertIsObject($find[0]);
        $this->assertCount(2, $find);
        //$this->assertObjectHasAttribute('attr', $find[0]);
    }

    /**
     * Find by condition in
     *
     */
    public function testWhereIn(): void
    {
        $find = Article::query()->whereIn('id', [1, 3, 5, 7])->get();

        $this->assertInstanceOf(Collection::class, $find);
        $this->assertCount(4, $find);
        $this->assertEquals('1', $find[0]->id);
        $this->assertEquals('3', $find[1]->id);
        $this->assertEquals('5', $find[2]->id);
        $this->assertEquals('7', $find[3]->id);
    }

    /**
     * Find by condition not in
     *
     */
    public function testWhereNotIn(): void
    {
        $find = Article::query()->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])->get();

        $this->assertInstanceOf(Collection::class, $find);
        $this->assertCount(10, $find);
        $this->assertEquals('11', $find[0]->id);
        $this->assertEquals('12', $find[1]->id);
        $this->assertEquals('13', $find[2]->id);
        $this->assertEquals('14', $find[3]->id);
        $this->assertEquals('15', $find[4]->id);
        $this->assertEquals('16', $find[5]->id);
        $this->assertEquals('17', $find[6]->id);
        $this->assertEquals('18', $find[7]->id);
        $this->assertEquals('19', $find[8]->id);
        $this->assertEquals('20', $find[9]->id);
    }

    /**
     * Get count
     *
     */
    public function testCount(): void
    {
        $find = Article::query()->count();

        $this->assertEquals(20, $find);
    }

    /**
     * Get where count
     *
     */
    public function testWhereCount(): void
    {
        $find = Article::query()->where('created_at', '>', '2009-01-06 08:40:34')->count();

        $this->assertEquals(3, $find);
    }

    /**
     * Get lines 1 - 10
     *
     */
    public function testOffsetLimitGet(): void
    {
        $find = Article::query()->offset(0)->limit(10)->get();

        $this->assertCount(10, $find);
        $this->assertEquals('Заголовок1', $find[0]->title);
        $this->assertEquals('Заголовок7', $find[6]->title);
        $this->assertEquals('Заголовок10', $find[9]->title);
    }

    /**
     * Get headers
     *
     */
    public function testHeaders(): void
    {
        $find = Article::query()->headers();

        $this->assertCount(5, $find);
        $this->assertEquals('id', $find[0]);
        $this->assertEquals('name', $find[1]);
        $this->assertEquals('title', $find[2]);
        $this->assertEquals('text', $find[3]);
        $this->assertEquals('created_at', $find[4]);
    }

    /**
     * Get first line
     *
     */
    public function testFirst(): void
    {
        $find = Article::query()->first();

        $this->assertIsObject($find);
        //$this->assertObjectHasAttribute('attr', $find);
        $this->assertEquals('Петя', $find->name);
        $this->assertEquals('Заголовок1', $find->title);
    }

    /**
     * Get first line empty
     *
     */
    public function testFirstEmpty(): void
    {
        $find = Item::query()->first();

        $this->assertNull($find);
    }

    /**
     * Get where first line empty
     *
     */
    public function testWhereFirstEmpty(): void
    {
        $find = Article::query()->where('name', 'something')->first();

        $this->assertNull($find);
    }

    /**
     * Get first 3 lines
     *
     */
    public function testFirst3(): void
    {
        $find = Article::query()->limit(3)->get();

        $this->assertCount(3, $find);
        //$this->assertObjectHasAttribute('attr', $find[0]);
        $this->assertEquals('Петя', $find[0]->name);
        $this->assertEquals('Заголовок1', $find[0]->title);
    }

    /**
     * Get last 3 lines
     *
     */
    public function testLast3(): void
    {
        $find = Article::query()->orderByDesc('id')->limit(3)->get();

        $this->assertCount(3, $find);
        //$this->assertObjectHasAttribute('attr', $find[0]);
        $this->assertEquals('Петя', $find[0]->name);
        $this->assertEquals('Заголовок20', $find[0]->title);
    }

    /**
     * Find by string primary key
     */
    public function testFindStringKey(): void
    {
        $find = Setting::query()->find('key1');

        $this->assertIsObject($find);
        $this->assertEquals('key1', $find->key);
        $this->assertEquals('500', $find->value);
    }

    /**
     * Find by empty string primary key
     */
    public function testFindEmptyStringKey(): void
    {
        $find = Setting::query()->find('key3');

        $this->assertIsObject($find);
        $this->assertEquals('key3', $find->key);
        $this->assertEquals('', $find->value);
    }

    /**
     * Get all
     */
    public function testAllGet(): void
    {
        $find = Setting::query()->get();

        $this->assertCount(5, $find);
        //$this->assertObjectHasAttribute('attr', $find[0]);
        $this->assertEquals('key1', $find[0]->key);
        $this->assertEquals('500', $find[0]->value);
    }

    /**
     * Find by name and sort (created_at asc)
     */
    public function testSort(): void
    {
        $find = Article::query()->where('name', 'Миша')->orderBy('created_at')->limit(3)->get();

        $this->assertCount(3, $find);
        $this->assertEquals(10, $find[0]->id);
        $this->assertEquals(18, $find[2]->id);
    }

    /**
     * Find by name and double sort (created_at desc, id desc)
     */
    public function testDoubleSort(): void
    {
        $find = Article::query()->where('name', 'Миша')->orderBy('created_at', SortOrder::Desc)->orderByDesc('id')->limit(3)->get();

        $this->assertCount(3, $find);
        $this->assertEquals(18, $find[0]->id);
        $this->assertEquals(10, $find[2]->id);
    }

    /**
     * Create field
     */
    public function testCreate(): void
    {
        $data = Item::query()->create([
           'name' => 'name1',
           'value' => 555,
        ]);

        $find = Item::query()->orderByDesc('id')->first();

        $this->assertEquals($find->id, $data->id);
        //$this->assertObjectHasAttribute('attr', $find);
        $this->assertEquals('name1', $find->name);
        $this->assertEquals('555', $find->value);

        Item::query()->truncate();
    }

    /**
     * A value json cannot hold is an error, not a row with a missing column
     */
    public function testUnencodableValueIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('value');

        Item::query()->create([
            'name'  => 'name1',
            'value' => ['rate' => NAN],
        ]);
    }

    /**
     * A write that fails halfway leaves the table as it was
     */
    public function testFailedWriteLeavesTheTableAlone(): void
    {
        Item::query()->create(['name' => 'one', 'value' => 'first']);

        try {
            Item::query()->where('id', 1)->update(['value' => ['rate' => NAN]]);
        } catch (UnexpectedValueException) {
            // the row is the point, not the exception
        }

        $this->assertCount(1, Item::query()->get());
        $this->assertEquals('first', Item::query()->find(1)->value);
    }

    /**
     * Create multiple fields
     */
    public function testMultipleCreate(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        $find = Item::query()->get();

        $this->assertCount(6, $find);
        $this->assertEquals('name3', $find[2]->name);
        $this->assertEquals('value3', $find[2]->value);

        Item::query()->truncate();
    }

    /**
     * Update fields
     */
    public function testFindUpdate(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        $updatedLines = Item::query()->find(1)->update(['name' => 'yyy', 'value' => 999]);

        $find = Item::query()->find(1);

        $this->assertEquals(1, $updatedLines);
        $this->assertEquals('yyy', $find->name);
        $this->assertEquals('999', $find->value);

        Item::query()->truncate();
    }

    /**
     * Update fields
     */
    public function testUpdate(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        Item::query()->where('id', 3)->update(['name' => 'xxx', 'value' => 888]);

        $find = Item::query()->find(3);

        $this->assertEquals('xxx', $find->name);
        $this->assertEquals('888', $find->value);

        Item::query()->truncate();
    }

    /**
     * Delete fields
     */
    public function testDelete(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }
        Item::query()->where('id', 3)->delete();

        $find = Item::query()->find(3);

        $this->assertNull($find);

        Item::query()->truncate();
    }

    /**
     * Truncate fields
     */
    public function testTruncate(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        Item::query()->truncate();

        $find = Item::query()->get();
        $this->assertCount(0, $find);
    }

    /**
     * Save record with an integer primary key
     */
    public function testSave(): void
    {
        foreach ($this->data() as $val) {
            Item::query()->create($val);
        }

        $record = Item::query()->find(2);
        $record->name = 'saved';
        $saved = $record->save();

        $find = Item::query()->find(2);

        $this->assertTrue($saved);
        $this->assertEquals('saved', $find->name);
        $this->assertCount(6, Item::query()->get());

        Item::query()->truncate();
    }

    /**
     * Save record with a string primary key
     */
    public function testSaveStringKey(): void
    {
        $record = Setting::query()->find('key2');
        $record->value = 'изменено';
        $saved = $record->save();

        $find = Setting::query()->find('key2');

        $this->assertTrue($saved);
        $this->assertEquals('изменено', $find->value);
        $this->assertCount(5, Setting::query()->get());

        $find->value = '1000';
        $find->save();
    }

    /**
     * Create with an undefined column
     */
    public function testCreateUndefinedColumn(): void
    {
        $this->expectException(UnexpectedValueException::class);

        try {
            Item::query()->create(['name' => 'name1', 'undefined' => 1]);
        } finally {
            Item::query()->truncate();
        }
    }

    /**
     * Create with a duplicate primary key
     */
    public function testCreateDuplicate(): void
    {
        $this->expectException(UnexpectedValueException::class);

        try {
            Item::query()->create(['id' => 1, 'name' => 'name1']);
            Item::query()->create(['id' => 1, 'name' => 'name2']);
        } finally {
            Item::query()->truncate();
        }
    }

    /**
     * A non numeric primary key cannot be generated, it has to be given
     */
    public function testCreateWithoutGeneratableKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('no unique ID assigned');

        Setting::query()->create(['value' => 'значение']);
    }

    /**
     * Update with an undefined column
     */
    public function testUpdateUndefinedColumn(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Item::query()->update(['undefined' => 1]);
    }

    /**
     * Create with an explicit primary key
     */
    public function testCreateExplicitKey(): void
    {
        Item::query()->create(['id' => 100, 'name' => 'name1']);
        Item::query()->create(['name' => 'name2']);

        $find = Item::query()->get();

        $this->assertCount(2, $find);
        $this->assertEquals(100, $find[0]->id);
        $this->assertEquals(101, $find[1]->id);

        Item::query()->truncate();
    }

    /**
     * Exists
     */
    public function testExists(): void
    {
        $this->assertTrue(Article::query()->where('id', 1)->exists());
        $this->assertFalse(Article::query()->where('id', 999)->exists());
    }

    /**
     * A column named like a builder method must be read as a column
     */
    public function testColumnShadowingMethodName(): void
    {
        $find = Reserved::query()->find(1);

        $this->assertEquals(10, $find->count);
        $this->assertEquals('Первый', $find->first);
    }

    /**
     * Convert record to array
     */
    public function testToArray(): void
    {
        $find = Article::query()->find(1);

        $this->assertSame([
            'id'    => 1,
            'name'  => 'Петя',
            'title' => 'Заголовок1',
            'text'  => 'Текст',
            'created_at' => '2009-01-06 08:40:34',
        ], $find->toArray());
    }

    /**
     * Isset on attributes
     */
    public function testIssetAndSet(): void
    {
        $find = Article::query()->find(1);

        $this->assertTrue(isset($find->name));
        $this->assertFalse(isset($find->undefined));
        $this->assertNull($find->undefined);

        $find->undefined = 'value';
        $this->assertTrue(isset($find->undefined));
        $this->assertEquals('value', $find->undefined);
    }

    /**
     * @return array
     */
    private function data(): array
    {
        return [
            [
                'name' => 'name1',
                'value' => 555,
            ],
            [
                'name' => 'name2',
                'value' => 777,
            ],
            [
                'name' => 'name3',
                'value' => 'value3',
            ],
            [
                'name' => 'name4',
                'value' => null,
            ],
            [
                'name' => 'name5',
            ],
            [
                'name' => 'name6',
                'value' => ',',
            ],
        ];
    }
}
