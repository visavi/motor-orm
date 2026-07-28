<?php

namespace MotorORM\Tests;

use MotorORM\Tests\Models\Bulk;
use UnexpectedValueException;

final class BrokenTableTest extends TestCase
{
    /**
     * Write a table by hand, so that it can hold what the orm would never
     * write itself
     */
    private function table(string ...$lines): void
    {
        file_put_contents(new Bulk()->getPath(), implode("\n", $lines) . "\n");
    }

    protected function tearDown(): void
    {
        @unlink(new Bulk()->getPath());
    }

    /**
     * A row shorter than the header reads as far as it goes, the rest empty
     */
    public function testRowShorterThanTheHeader(): void
    {
        $this->table('id,title,text', '1,Заголовок', '2,Заголовок2,Текст');

        $short = Bulk::query()->find(1);

        $this->assertEquals(1, $short->id);
        $this->assertSame('Заголовок', $short->title);
        $this->assertNull($short->text);
        $this->assertSame('Текст', Bulk::query()->find(2)->text);
    }

    /**
     * A row longer than the header keeps the columns the table names
     */
    public function testRowLongerThanTheHeader(): void
    {
        $this->table('id,title,text', '1,Заголовок,Текст,лишнее');

        $long = Bulk::query()->find(1);

        $this->assertSame(['id', 'title', 'text'], array_keys($long->toArray()));
        $this->assertSame('Текст', $long->text);
    }

    /**
     * An empty line is no row, and rewriting the table drops it
     */
    public function testEmptyLineIsNoRow(): void
    {
        $this->table('id,title,text', '1,Заголовок1,Текст', '', '2,Заголовок2,Текст');

        $this->assertSame(2, Bulk::query()->count());

        Bulk::query()->where('id', 1)->update(['text' => 'Другой']);

        $this->assertSame(2, Bulk::query()->count());
        $this->assertStringNotContainsString("\n\n", file_get_contents(new Bulk()->getPath()));
    }

    /**
     * A write to a table that is not there says so instead of creating it
     */
    public function testWritingToAMissingTable(): void
    {
        @unlink(new Bulk()->getPath());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('does not exist');

        Bulk::query()->delete();
    }

    /**
     * A row without a key is not one the next key can be continued from
     */
    public function testRowWithoutAKey(): void
    {
        $this->table('id,title,text', '1,Заголовок1,Текст', ',Безымянная,Текст');

        $created = Bulk::query()->create(['title' => 'Новая', 'text' => 'Текст']);

        $this->assertEquals(2, $created->id);
    }
}
