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
     * The path the file was opened by
     */
    public function testPathname(): void
    {
        $this->assertSame($this->path, $this->file('id,title')->getPathname());
    }
}
