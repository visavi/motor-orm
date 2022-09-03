<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Test2
 *
 * @property string $key
 * @property string $value
 */
class Test2 extends Builder
{
    public string $filePath = __DIR__ . '/../../tests/data/test2.csv';

    /**
     * The attributes that should be cast.
     */
    protected array $casts = [
        'key' => 'string',
    ];
}
