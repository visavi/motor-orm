<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Table with no file of its own, created and dropped inside the tests
 */
class Scratch extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/scratch.csv';
}
