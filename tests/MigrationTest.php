<?php

namespace MotorORM\Tests;

use MotorORM\Migration;
use MotorOrm\Tests\Models\Test4;
use MotorORM\Tests\Models\Test5;

/**
 * @coversDefaultClass \MotorORM\Migration
 */
final class MigrationTest extends TestCase
{
    /**
     * Create column
     * @covers ::changeTable()
     */
    public function testCreateColumn(): void
    {
        $migration = new Migration(new Test4());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4');
        });

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column3', 'column4']);

        $migration->changeTable(function (Migration $table) {
            $table->delete('column4');
        });
    }

    /**
     * Create column after column
     * @covers ::changeTable()
     */
    public function testCreateColumnAfter(): void
    {
        $migration = new Migration(new Test4());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4')->after('column1');
        });

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column4', 'column2', 'column3']);

        $migration->changeTable(function (Migration $table) {
            $table->delete('column4');
        });
    }

    /**
     * Create column default column
     * @covers ::changeTable()
     */
    public function testCreateColumnDefault(): void
    {
        $migration = new Migration(new Test4());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4')->default('xxx')->after('column2');
        });

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column4', 'column3']);

        $find = Test4::query()->find(3);
        $this->assertNotNull($find->column4);
        $this->assertEquals('xxx', $find->column4);

        $migration->changeTable(function (Migration $table) {
            $table->delete('column4');
        });
    }

    /**
     * Rename column
     * @covers ::changeTable()
     */
    public function testRenameColumn(): void
    {
        $migration = new Migration(new Test4());
        $migration->changeTable(function (Migration $table) {
            $table->rename('column3', 'column4');
        });

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(3, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column4']);

        $migration->changeTable(function (Migration $table) {
            $table->rename('column4', 'column3');
        });
    }

    /**
     * Delete column
     * @covers ::changeTable()
     */
    public function testDeleteColumn(): void
    {
        $migration = new Migration(new Test4());
        $migration->changeTable(function (Migration $table) {
            $table->delete('column3');
        });

        $headers = Test4::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(2, $headers);
        $this->assertNotContains('column3', $headers);
        $this->assertSame($headers, ['column1', 'column2']);

        $migration->changeTable(function (Migration $table) {
            $table->create('column3')->default('value');
        });
    }

    /**
     * Create table
     * @covers ::createTable()
     */
    public function testCreateTable(): void
    {
        $migration = new Migration(new Test5());
        $migration->createTable(function (Migration $table) {
            $table->create('column1');
            $table->create('column2');
            $table->create('column3');
            $table->create('column4');
        });

        $headers = Test5::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column3', 'column4']);
    }

    /**
     * Delete table
     * @covers ::deleteTable()
     */
    public function testDeleteTable(): void
    {
        $migration = new Migration(new Test5());
        $migration->deleteTable();

        $this->assertFalse($migration->hasTable());
    }
}
