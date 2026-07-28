<?php

namespace MotorORM\Tests\Models;

/**
 * The same table with the types spelled out
 *
 * @property int $id
 * @property int $updated_at
 */
class CastedEvent extends Event
{
    protected array $casts = [
        'id'         => 'int',
        'updated_at' => 'int',
    ];
}
