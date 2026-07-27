<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class User
 *
 * @property int $id
 * @property string $login
 */
class User extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/users.csv';

    /**
     * Stories relation
     *
     * @return Builder
     */
    public function stories(): Builder
    {
        return $this->hasMany(Story::class);
    }
}
