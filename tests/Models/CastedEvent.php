<?php

namespace MotorORM\Tests\Models;

/**
 * The same table with the types spelled out
 *
 * The key is not among them: a numeric one reads back as an int on its own
 *
 * @property int $views
 */
class CastedEvent extends Event
{
    protected array $casts = [
        'views' => 'int',
    ];
}
