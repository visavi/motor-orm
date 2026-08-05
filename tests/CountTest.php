<?php

namespace MotorORM\Tests;

use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Scratch;

/**
 * A count with nothing to match only counts the rows, and a count that has to
 * match reads them. Both have to come to the same number
 */
final class CountTest extends TestCase
{
    private string $path = __DIR__ . '/data/scratch.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function table(string ...$lines): void
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");
    }

    /**
     * Counting the rows agrees with reading them
     */
    public function testCountAgreesWithReading(): void
    {
        $this->assertSame(count(Article::query()->get()), Article::query()->count());
    }

    /**
     * A value holding a newline is one row, not two
     */
    public function testValueHoldingANewline(): void
    {
        $this->table('id,title', '1,"Первый', 'с переносом"', '2,Второй');

        $this->assertSame(2, Scratch::query()->count());
        $this->assertSame(2, count(Scratch::query()->get()));
    }

    /**
     * Blank lines are no rows
     */
    public function testBlankLines(): void
    {
        $this->table('id,title', '1,Первый', '', '2,Второй', '');

        $this->assertSame(2, Scratch::query()->count());
    }

    /**
     * A table of nothing but its header holds no rows
     */
    public function testTableWithoutRows(): void
    {
        $this->table('id,title');

        $this->assertSame(0, Scratch::query()->count());
    }

    /**
     * A count that has to match still reads the rows
     */
    public function testCountWithConditions(): void
    {
        $this->assertSame(3, Article::query()->where('name', 'Миша')->count());
    }
}
