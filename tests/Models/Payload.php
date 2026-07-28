<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;

/**
 * Table with a column holding json
 *
 * @property int   $id
 * @property array $meta
 */
class Payload extends Model
{
    public string $table = __DIR__ . '/../../tests/data/payloads.csv';

    protected array $casts = [
        'meta' => 'array',
    ];
}
