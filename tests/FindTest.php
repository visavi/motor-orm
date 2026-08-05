<?php

namespace MotorORM\Tests;

use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Scratch;
use MotorORM\Tests\Models\Story;

/**
 * A lookup by primary key halves the file when it can and reads it row by row
 * when it cannot. Which of the two happened is nobody's business: the answer
 * has to be the same either way, and these are the tables where the two ways
 * could tell apart
 */
final class FindTest extends TestCase
{
    private string $path = __DIR__ . '/data/scratch.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Write a table by hand, rows lying in the order they are given
     */
    private function table(string ...$lines): void
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");
    }

    /**
     * Keys put out of order by hand are still found
     */
    public function testKeysOutOfOrder(): void
    {
        $this->table('id,title', '40,Сороковой', '2,Второй', '17,Семнадцатый');

        $this->assertSame('Второй', Scratch::query()->find(2)->title);
        $this->assertSame('Сороковой', Scratch::query()->find(40)->title);
        $this->assertSame('Семнадцатый', Scratch::query()->find(17)->title);
    }

    /**
     * A key that is nowhere in an unordered table comes back as nothing
     */
    public function testMissingKeyOutOfOrder(): void
    {
        $this->table('id,title', '40,Сороковой', '2,Второй');

        $this->assertNull(Scratch::query()->find(17));
    }

    /**
     * A value holding a newline of its own does not throw the lookup off
     */
    public function testValueHoldingANewline(): void
    {
        $this->table(
            'id,title',
            '1,"Первый',
            'с переносом"',
            '2,Второй',
            '3,Третий',
        );

        $this->assertSame("Первый\nс переносом", Scratch::query()->find(1)->title);
        $this->assertSame('Второй', Scratch::query()->find(2)->title);
        $this->assertSame('Третий', Scratch::query()->find(3)->title);
    }

    /**
     * Conditions gathered before the lookup still have their say
     */
    public function testConditionsAreKept(): void
    {
        $this->assertNull(Article::query()->where('name', 'Миша')->find(1));
        $this->assertSame('Заголовок10', Article::query()->where('name', 'Миша')->find(10)->title);
    }

    /**
     * A relation asked for beforehand comes with the record
     */
    public function testRelationsAreLoaded(): void
    {
        $story = Story::query()->with('user')->find(1);

        $this->assertSame('admin', $story->user->login);
    }

    /**
     * A key given as a string finds the same row a number would
     */
    public function testKeyGivenAsString(): void
    {
        $this->assertSame('Заголовок7', Article::query()->find('7')->title);
    }
}
