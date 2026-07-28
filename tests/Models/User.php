<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;
use MotorORM\Relation;

/**
 * Class User
 *
 * @property int $id
 * @property string $login
 */
class User extends Model
{
    public string $table = __DIR__ . '/../../tests/data/users.csv';

    /**
     * Stories relation
     *
     * @return Relation
     */
    public function stories(): Relation
    {
        return $this->hasMany(Story::class);
    }
}
