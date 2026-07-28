<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;

/**
 * Table declaring a cast of every kind
 *
 * @property int    $id
 * @property float  $ratio
 * @property string $label
 * @property bool   $enabled
 * @property array  $tags
 * @property object $shape
 */
class Measure extends Model
{
    public string $table = __DIR__ . '/../../tests/data/measures.csv';

    protected array $casts = [
        'ratio'   => 'float',
        'label'   => 'string',
        'enabled' => 'bool',
        'tags'    => 'array',
        'shape'   => 'object',
    ];
}
