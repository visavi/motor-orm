<?php

namespace MotorORM\Tests;

use MotorORM\KeySearch;
use MotorORM\Table;
use MotorORM\Tests\Models\Scratch;

final class KeySearchTest extends TestCase
{
    private string $path = __DIR__ . '/data/scratch.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * A table written by hand, rows given as they are to lie in the file
     */
    private function search(string ...$lines): KeySearch
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");

        return new KeySearch(new Table(new Scratch()));
    }

    /**
     * A table of rows in the order the keys were handed out
     */
    private function ascending(int $rows): KeySearch
    {
        $lines = ['id,title'];

        for ($id = 1; $id <= $rows; $id++) {
            $lines[] = $id . ',Заголовок' . $id;
        }

        return $this->search(...$lines);
    }

    /**
     * A key is looked up wherever in the file it lies
     */
    public function testFindsAKeyAnywhereInTheTable(): void
    {
        $search = $this->ascending(500);

        $this->assertSame(['1', 'Заголовок1'], $search->row('1'));
        $this->assertSame(['250', 'Заголовок250'], $search->row('250'));
        $this->assertSame(['500', 'Заголовок500'], $search->row('500'));
    }

    /**
     * Every key of the table is found, whichever way the halving fell
     */
    public function testFindsEveryKey(): void
    {
        $search = $this->ascending(64);

        for ($id = 1; $id <= 64; $id++) {
            $this->assertSame([(string) $id, 'Заголовок' . $id], $search->row((string) $id));
        }
    }

    /**
     * A key the table does not hold is not found
     */
    public function testKeyThatIsNotThere(): void
    {
        $search = $this->ascending(20);

        $this->assertNull($search->row('21'));
        $this->assertNull($search->row('0'));
    }

    /**
     * Rows removed leave the keys apart, and the ones left are still found
     */
    public function testKeysWithGaps(): void
    {
        $search = $this->search('id,title', '3,Третий', '17,Семнадцатый', '90,Девяностый');

        $this->assertSame(['17', 'Семнадцатый'], $search->row('17'));
        $this->assertSame(['90', 'Девяностый'], $search->row('90'));
        $this->assertNull($search->row('18'));
    }

    /**
     * A search of a table with nothing but its header finds nothing
     */
    public function testTableWithoutRows(): void
    {
        $this->assertNull($this->search('id,title')->row('1'));
    }

    /**
     * A value that reads like a row of its own is not one
     *
     * The text below holds a newline and behind it something that has the
     * columns of the table and the key being looked for. The row it belongs to
     * is the first one, and the second row is the one that answers
     */
    public function testValueForgingARow(): void
    {
        $search = $this->search(
            'id,title',
            '1,"Первый',
            '2,Подделка"',
            '2,Второй',
            '3,Третий',
        );

        $this->assertSame(['2', 'Второй'], $search->row('2'));
    }

    /**
     * Values holding newlines do not call the search off
     *
     * Half the bytes of such a table lie inside a value, so half the places a
     * halving lands are the middle of a row. Stepping on to the row that
     * follows is enough, and the search goes on
     */
    public function testValuesHoldingNewlines(): void
    {
        $lines = ['id,title'];

        for ($id = 1; $id <= 64; $id++) {
            $lines[] = sprintf('%d,"Заголовок', $id);
            $lines[] = sprintf('с переносом %d"', $id);
        }

        $search = $this->search(...$lines);

        foreach ([1, 17, 33, 64] as $id) {
            $this->assertSame(
                [(string) $id, sprintf("Заголовок\nс переносом %d", $id)],
                $search->row((string) $id),
            );
        }
    }

    /**
     * A file without even a header is a table without columns
     */
    public function testFileWithNothingInIt(): void
    {
        file_put_contents($this->path, '');

        $this->assertNull((new KeySearch(new Table(new Scratch())))->row('1'));
    }

    /**
     * Blank lines at the end of the file are stepped over, not read as rows
     */
    public function testBlankLinesAtTheEnd(): void
    {
        $search = $this->search('id,title', '1,Первый', '2,Второй', '', '', '');

        $this->assertSame(['2', 'Второй'], $search->row('2'));
        $this->assertNull($search->row('3'));
    }

    /**
     * A last row of the wrong width is caught where the search comes to rest
     */
    public function testLastRowOfTheWrongWidth(): void
    {
        $search = $this->search('id,title', '1,Первый', '2,Второй', '3,Третий,лишнее');

        $this->assertNull($search->row('3'));
    }

    /**
     * Halving the file only tells anything when the keys are whole numbers
     */
    public function testKeyThatIsNotAWholeNumber(): void
    {
        $search = $this->search('id,title', 'a1,Первый', 'b2,Второй');

        $this->assertNull($search->row('b2'));
    }

    /**
     * A table whose keys are not whole numbers is left alone, however it is asked
     */
    public function testTableWhoseKeysAreNotWholeNumbers(): void
    {
        $search = $this->search('id,title', 'aaa,Первый', 'bbb,Второй', 'ccc,Третий');

        $this->assertNull($search->row('1'));
    }

    /**
     * A row of the wrong width means the file is not being read where rows begin
     */
    public function testRowOfTheWrongWidth(): void
    {
        $search = $this->search('id,title', '1,Первый', '2,Второй,лишнее', '3,Третий');

        $this->assertNull($search->row('2'));
    }
}
