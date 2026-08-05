<?php

namespace MotorORM\Tests;

use MotorORM\Model;
use MotorORM\Tests\Models\Measure;
use MotorORM\CsvFile;

final class CastTest extends TestCase
{
    /**
     * A table of its own, because the cases write to it
     */
    protected function setUp(): void
    {
        $file = new CsvFile(new Measure()->getPath(), 'wb', ...Model::CSV_CONTROL);
        $file->fputcsv(['id', 'ratio', 'label', 'enabled', 'tags', 'shape']);
        $file->fputcsv(['1', '1.5', '42', '1', '["a","b"]', '{"side":3}']);
        $file->fputcsv(['2', '0', 'text', '0', '[]', '{}']);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(new Measure()->getPath());
    }

    /**
     * A value comes back as the type its column was cast to, whatever the
     * file holds it as
     */
    public function testEveryCastReadsBack(): void
    {
        $first = Measure::query()->find(1);

        $this->assertSame(1.5, $first->ratio);
        $this->assertSame('42', $first->label);
        $this->assertTrue($first->enabled);
        $this->assertSame(['a', 'b'], $first->tags);
        $this->assertEquals(3, $first->shape->side);
    }

    /**
     * A column holding nothing is still of its type
     */
    public function testCastsOfEmptyValues(): void
    {
        $second = Measure::query()->find(2);

        $this->assertSame(0.0, $second->ratio);
        $this->assertSame('text', $second->label);
        $this->assertFalse($second->enabled);
        $this->assertSame([], $second->tags);
        $this->assertEquals(new \stdClass(), $second->shape);
    }

    /**
     * What was written is what reads back, false among it
     *
     * A false written as it is would leave an empty column, which reads back
     * as false as well but says nothing about what was meant
     */
    public function testValuesSurviveTheirCastOnTheWayOut(): void
    {
        $created = Measure::query()->create([
            'ratio'   => 2.5,
            'label'   => 'written',
            'enabled' => false,
            'tags'    => ['x'],
            'shape'   => (object) ['side' => 4],
        ]);

        $read = Measure::query()->find($created->id);

        $this->assertSame(2.5, $read->ratio);
        $this->assertSame('written', $read->label);
        $this->assertFalse($read->enabled);
        $this->assertSame(['x'], $read->tags);
        $this->assertEquals(4, $read->shape->side);

        $line = file(new Measure()->getPath())[3];
        $this->assertStringContainsString('2.5,written,0', $line);
    }

    /**
     * A true is written as a one, not as the word
     */
    public function testTrueIsWrittenAsOne(): void
    {
        Measure::query()->create(['ratio' => 1, 'label' => 'on', 'enabled' => true, 'tags' => [], 'shape' => (object) []]);

        $line = file(new Measure()->getPath())[3];

        $this->assertStringContainsString('1,on,1', $line);
        $this->assertTrue(Measure::query()->find(3)->enabled);
    }
}
