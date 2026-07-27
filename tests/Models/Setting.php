<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Table with a string primary key
 *
 * @property string $key
 * @property string $value
 */
class Setting extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/settings.csv';

    /**
     * The attributes that should be cast.
     */
    protected array $casts = [
        'key' => 'string',
    ];
}
