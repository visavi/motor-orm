<?php

namespace MotorORM\Tests\Records;

use MotorORM\Record;

/**
 * A row of the articles table, with something to say for itself
 */
class PublicationRecord extends Record
{
    /**
     * @return string
     */
    public function shout(): string
    {
        return mb_strtoupper((string) $this->title);
    }
}
