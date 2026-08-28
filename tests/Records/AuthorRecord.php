<?php

namespace MotorORM\Tests\Records;

use MotorORM\Record;

/**
 * A row of the users table, empty when the relation found nobody
 */
class AuthorRecord extends Record
{
    /**
     * @return bool
     */
    public function isKnown(): bool
    {
        return $this->id !== null;
    }
}
