<?php

namespace MotorORM\Tests;

use MotorORM\Migration;
use MotorOrm\Tests\Models\Test4;

/**
 * @coversDefaultClass \MotorORM\Migration
 */
final class MigrationTest extends TestCase
{
    /**
     * Create
     * @covers ::create()
     */
    public function testCreate(): void
    {
        $migration = new Migration(new Test4());
        $migration->column('column4')->create();

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column3', 'column4']);

        $migration->column('column4')->delete();
    }

    /**
     * Create after column
     * @covers ::create()
     */
    public function testCreateAfter(): void
    {
        $migration = new Migration(new Test4());
        $migration->column('column4')->after('column1')->create();

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column4', 'column2', 'column3']);

        $migration->column('column4')->delete();
    }

    /**
     * Create default column
     * @covers ::create()
     */
    public function testCreateDefault(): void
    {
        $migration = new Migration(new Test4());
        $migration->column('column4')->default('xxx')->after('column2')->create();

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column4', 'column3']);

        $find = Test4::query()->find(3);
        $this->assertNotNull($find->column4);
        $this->assertEquals('xxx', $find->column4);

        $migration->column('column4')->delete();
    }

    /**
     * Rename
     * @covers ::rename()
     */
    public function testRename(): void
    {
        $migration = new Migration(new Test4());
        $migration->column('column3')->to('column4')->rename();

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(3, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column4']);

        $migration->column('column4')->to('column3')->rename();
    }

    /**
     * Delete
     * @covers ::delete()
     */
    public function testDelete(): void
    {
        $migration = new Migration(new Test4());
        $migration->column('column3')->delete();

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(2, $headers);
        $this->assertNotContains('column3', $headers);
        $this->assertSame($headers, ['column1', 'column2']);

        $migration->column('column3')->default('value')->create();
    }
}
