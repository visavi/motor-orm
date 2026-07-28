<?php

namespace MotorORM\Tests;

use MotorORM\Model;
use MotorORM\Tests\Models\Bulk;
use SplFileObject;
use UnexpectedValueException;

final class InsertTest extends TestCase
{
    /** Rows the table holds before anything is added to it */
    private const int ROWS = 20000;

    /**
     * A table large enough for holding it to be noticeable
     */
    public static function setUpBeforeClass(): void
    {
        $file = new SplFileObject(new Bulk()->getPath(), 'wb');
        $file->setCsvControl(...Model::CSV_CONTROL);
        $file->fputcsv(['id', 'title', 'text']);

        for ($i = 1; $i <= self::ROWS; $i++) {
            $file->fputcsv([$i, 'Заголовок' . $i, 'Текст текст текст']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(new Bulk()->getPath());
    }

    /**
     * A row is added without holding the keys of the table
     *
     * The next key and a taken one are both answered by looking at the rows as
     * they go by, so what a table costs to write to does not grow with it
     */
    public function testCreateDoesNotHoldTheKeys(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $record = Bulk::query()->create(['title' => 'Новый', 'text' => 'Текст']);

        $this->assertEquals(self::ROWS + 1, $record->id);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * A key already in the table is still refused
     */
    public function testDuplicateKeyIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('duplicate entry');

        Bulk::query()->create(['id' => 7, 'title' => 'Новый', 'text' => 'Текст']);
    }

    /**
     * A key of its own is taken as it is, the count keeps going from there
     */
    public function testKeyOfItsOwnIsKept(): void
    {
        $record = Bulk::query()->create(['id' => 90000, 'title' => 'Новый', 'text' => 'Текст']);
        $next   = Bulk::query()->create(['title' => 'Ещё', 'text' => 'Текст']);

        $this->assertEquals(90000, $record->id);
        $this->assertEquals(90001, $next->id);
    }
}
