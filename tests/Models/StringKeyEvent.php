<?php

namespace MotorORM\Tests\Models;

/**
 * The primary key kept as a string on purpose
 *
 * @property string $id
 */
class StringKeyEvent extends Event
{
    protected array $casts = [
        'id' => 'string',
    ];
}
