<?php

namespace MotorORM\Tests;

use MotorORM\CsvFile;
use MotorORM\Model;
use RuntimeException;

final class CsvFileTest extends TestCase
{
    private string $path = __DIR__ . '/data/csvfile.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Write a file by hand, so that it can hold what the orm would never write
     */
    private function file(string ...$lines): CsvFile
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");

        return new CsvFile($this->path, 'a+b', ...Model::CSV_CONTROL);
    }

    /**
     * A file that cannot be opened is not a file to read
     */
    public function testOpeningWhatIsNotThere(): void
    {
        $this->expectException(RuntimeException::class);

        new CsvFile(__DIR__ . '/data/nothing/at/all.csv', 'rb');
    }

    /**
     * The file stands at its first row as soon as it is open
     */
    public function testStandsAtTheFirstRow(): void
    {
        $file = $this->file('id,title', '1,Заголовок');

        $this->assertSame(['id', 'title'], $file->current());
        $this->assertSame(0, $file->key());
        $this->assertTrue($file->valid());
    }

    /**
     * The rows come out in order, the key being the line they are on
     */
    public function testIterates(): void
    {
        $file = $this->file('id,title', '1,Первый', '2,Второй');

        $this->assertSame(
            [0 => ['id', 'title'], 1 => ['1', 'Первый'], 2 => ['2', 'Второй']],
            iterator_to_array($file),
        );

        $this->assertFalse($file->valid());
        $this->assertFalse($file->current());
    }

    /**
     * A walk of the rows leaves out the lines it was told to and the blank ones
     */
    public function testRowsSkipsTheHeaderAndBlankLines(): void
    {
        $file = $this->file('id,title', '1,Первый', '', '2,Второй');

        $this->assertSame(
            [1 => ['1', 'Первый'], 3 => ['2', 'Второй']],
            iterator_to_array($file->rows(1)),
        );
    }

    /**
     * Every walk starts the file over, so two of them read the same rows
     */
    public function testRowsStartOver(): void
    {
        $file = $this->file('id,title', '1,Первый');

        $this->assertSame(iterator_to_array($file->rows()), iterator_to_array($file->rows()));
    }

    /**
     * A value that holds the separator, a quote or a newline survives the trip
     */
    public function testValuesThatNeedQuoting(): void
    {
        $file = new CsvFile($this->path, 'wb', ...Model::CSV_CONTROL);
        $file->fputcsv(['id', 'title']);
        $file->fputcsv(['1', "Запятая, кавычка \" и\nперенос"]);
        unset($file);

        $read = new CsvFile($this->path, 'rb', ...Model::CSV_CONTROL);

        $this->assertSame(
            [1 => ['1', "Запятая, кавычка \" и\nперенос"]],
            iterator_to_array($read->rows(1)),
        );
    }

    /**
     * A value ending in a backslash closes where it ends, escaping nothing
     */
    public function testValueEndingInABackslash(): void
    {
        $file = new CsvFile($this->path, 'wb', ...Model::CSV_CONTROL);
        $file->fputcsv(['1', 'Путь\\', 'Дальше']);
        unset($file);

        $read = new CsvFile($this->path, 'rb', ...Model::CSV_CONTROL);

        $this->assertSame([['1', 'Путь\\', 'Дальше']], iterator_to_array($read->rows()));
    }

    /**
     * Standing at a line goes through the ones before it, forwards and back
     */
    public function testSeek(): void
    {
        $file = $this->file('id,title', '1,Первый', '2,Второй');

        $file->seek(2);
        $this->assertSame(['2', 'Второй'], $file->current());

        $file->seek(1);
        $this->assertSame(['1', 'Первый'], $file->current());

        /* Past the end there is nothing to stand at */
        $file->seek(10);
        $this->assertFalse($file->valid());
    }

    /**
     * A row written goes to the end of the file, whatever was read before it
     */
    public function testWritingAppends(): void
    {
        $file = $this->file('id,title', '1,Первый');

        $file->rewind();
        $file->fputcsv(['2', 'Второй']);

        $this->assertSame(
            [1 => ['1', 'Первый'], 2 => ['2', 'Второй']],
            iterator_to_array($file->rows(1)),
        );
    }

    /**
     * A read from a byte starts at the row that byte falls in the middle of
     */
    public function testRowFromLandsOnAWholeRow(): void
    {
        $file = $this->file('id,title', '1,Первый', '2,Второй');

        /* The header takes nine bytes, so the first row starts at the ninth */
        $this->assertSame([0, ['id', 'title']], $file->rowFrom(0));
        $this->assertSame([9, ['1', 'Первый']], $file->rowFrom(9));

        /* A byte in the middle of a line belongs to the line that follows it */
        $this->assertSame([9, ['1', 'Первый']], $file->rowFrom(4));
    }

    /**
     * Past the last row there is nothing to read
     */
    public function testRowFromPastTheEnd(): void
    {
        $file = $this->file('id,title', '1,Первый');

        $this->assertFalse($file->rowFrom(1000));
    }

    /**
     * A read leaves the file standing right after the row it gave out
     */
    public function testTellFollowsTheRowRead(): void
    {
        $file = $this->file('id,title', '1,Первый', '2,Второй');

        $file->rowFrom(0);
        $this->assertSame(9, $file->tell());

        [$start] = $file->rowFrom($file->tell());
        $this->assertSame(9, $start);
        $this->assertSame(24, $file->tell());
    }

    /**
     * A byte begins a record when the line before it is over and no value is
     * left open at it
     */
    public function testStartsRecord(): void
    {
        /* The header takes nine bytes, the row that follows holds a newline of
           its own inside a quoted value */
        $file = $this->file('id,title', '1,"Первый', 'с переносом"', '2,Второй');

        $this->assertTrue($file->startsRecord(0));
        $this->assertTrue($file->startsRecord(9));

        /* The line after the embedded newline looks like a row and is not one */
        $this->assertFalse($file->startsRecord(25));

        /* A byte in the middle of a line begins nothing */
        $this->assertFalse($file->startsRecord(12));

        /* The row that follows the quoted value does begin one */
        $this->assertTrue($file->startsRecord(48));
    }

    /**
     * The rows are counted without being read into arrays
     */
    public function testCountRows(): void
    {
        $file = $this->file('id,title', '1,Первый', '2,Второй');

        $this->assertSame(3, $file->countRows());
        $this->assertSame(2, $file->countRows(1));
    }

    /**
     * Blank lines are no rows, wherever they lie
     */
    public function testCountRowsSkipsBlankLines(): void
    {
        $file = $this->file('id,title', '1,Первый', '', '2,Второй', '');

        $this->assertSame(2, $file->countRows(1));
    }

    /**
     * A value holding a newline is one row, not two
     */
    public function testCountRowsWithANewlineInAValue(): void
    {
        $file = $this->file('id,title', '1,"Первый', 'с переносом"', '2,Второй');

        $this->assertSame(2, $file->countRows(1));
    }

    /**
     * A file with nothing in it has no rows
     */
    public function testCountRowsOfAnEmptyFile(): void
    {
        file_put_contents($this->path, '');

        $this->assertSame(0, (new CsvFile($this->path, 'rb', ...Model::CSV_CONTROL))->countRows());
    }

    /**
     * Counting the rows agrees with reading them, on every table the tests hold
     */
    public function testCountRowsAgreesWithReading(): void
    {
        foreach (glob(__DIR__ . '/data/*.csv') as $path) {
            $file = new CsvFile($path, 'rb', ...Model::CSV_CONTROL);

            $this->assertSame(
                iterator_count($file->rows(1)),
                $file->countRows(1),
                sprintf('таблица %s', basename($path)),
            );
        }
    }

    /**
     * The size of the file in bytes
     */
    public function testSize(): void
    {
        $this->assertSame(24, $this->file('id,title', '1,Первый')->size());
    }

    /**
     * The path the file was opened by
     */
    public function testPathname(): void
    {
        $this->assertSame($this->path, $this->file('id,title')->getPathname());
    }
}
