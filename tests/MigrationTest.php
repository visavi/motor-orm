<?php

namespace MotorORM\Tests;

use MotorORM\Migration;
use MotorORM\Tests\Models\Scratch;
use MotorORM\Tests\Models\Structure;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

#[CoversClass(Migration::class)]
final class MigrationTest extends TestCase
{
    /**
     * Scratch has no file of its own, every test that needs it creates it
     */
    protected function setUp(): void
    {
        @unlink(new Scratch()->getPath());
    }

    protected function tearDown(): void
    {
        @unlink(new Scratch()->getPath());
    }

    /**
     * Create column
     */
    public function testCreateColumn(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4');
        });

        $headers = Structure::query()->headers();
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
     */
    public function testCreateColumnAfter(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4')->after('column1');
        });

        $headers = Structure::query()->headers();
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
     */
    public function testCreateColumnDefault(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4')->default('xxx')->after('column2');
        });

        $headers = Structure::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertContains('column4', $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column4', 'column3']);

        $find = Structure::query()->find(3);
        $this->assertNotNull($find->column4);
        $this->assertEquals('xxx', $find->column4);

        $migration->changeTable(function (Migration $table) {
            $table->delete('column4');
        });
    }

    /**
     * Rename column
     */
    public function testRenameColumn(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->rename('column3', 'column4');
        });

        $headers = Structure::query()->headers();
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
     */
    public function testDeleteColumn(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->delete('column3');
        });

        $headers = Structure::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(2, $headers);
        $this->assertNotContains('column3', $headers);
        $this->assertSame($headers, ['column1', 'column2']);

        $migration->changeTable(function (Migration $table) {
            $table->create('column3')->default('value');
        });
    }

    /**
     * Several column changes in one call, positions resolve against the
     * headers as they change
     */
    public function testMultipleColumnChanges(): void
    {
        $migration = new Migration(new Structure());
        $migration->changeTable(function (Migration $table) {
            $table->create('column4')->default('four')->after('column1');
            $table->create('column5')->default('five')->before('column3');
            $table->rename('column2', 'renamed');
        });

        $headers = Structure::query()->headers();
        $this->assertSame(['column1', 'column4', 'renamed', 'column5', 'column3'], $headers);

        $find = Structure::query()->find(3);
        $this->assertEquals('key3', $find->renamed);
        $this->assertEquals('four', $find->column4);
        $this->assertEquals('five', $find->column5);
        $this->assertEquals('value', $find->column3);
        $this->assertCount(5, Structure::query()->get());

        $migration->changeTable(function (Migration $table) {
            $table->delete('column4');
            $table->delete('column5');
            $table->rename('renamed', 'column2');
        });

        $this->assertSame(['column1', 'column2', 'column3'], Structure::query()->headers());
    }

    /**
     * Create table
     */
    public function testCreateTable(): void
    {
        $migration = new Migration(new Scratch());
        $migration->createTable(function (Migration $table) {
            $table->create('column1');
            $table->create('column2');
            $table->create('column3');
            $table->create('column4');
        });

        $headers = Scratch::query()->headers();
        $this->assertIsArray($headers);
        $this->assertCount(4, $headers);
        $this->assertSame($headers, ['column1', 'column2',  'column3', 'column4']);
    }

    /**
     * Creating a table that already exists
     */
    public function testCreateExistingTable(): void
    {
        $migration = new Migration(new Scratch());
        $migration->createTable(function (Migration $table) {
            $table->create('column1');
        });

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('already exists');

        $migration->createTable(function (Migration $table) {
            $table->create('column1');
        });
    }

    /**
     * Deleting a table that does not exist
     */
    public function testDeleteMissingTable(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('does not exist');

        new Migration(new Scratch())->deleteTable();
    }

    /**
     * Asking whether a table exists must not create it
     */
    public function testHasTableDoesNotCreateTheFile(): void
    {
        $migration = new Migration(new Scratch());

        $this->assertFalse($migration->hasTable());
        $this->assertFileDoesNotExist(new Scratch()->getPath());
    }

    /**
     * Delete table
     */
    public function testDeleteTable(): void
    {
        $migration = new Migration(new Scratch());
        $migration->createTable(function (Migration $table) {
            $table->create('column1');
        });

        $this->assertTrue($migration->hasTable());

        $migration->deleteTable();

        $this->assertFalse($migration->hasTable());
        $this->assertFileDoesNotExist(new Scratch()->getPath());
    }
}
