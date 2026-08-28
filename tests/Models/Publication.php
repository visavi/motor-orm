<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Relation;
use MotorORM\Tests\Records\PublicationRecord;

/**
 * The articles table, read into a record of its own
 *
 * @property int $id
 * @property string $name
 * @property string $title
 */
class Publication extends Model
{
    public string $table = __DIR__ . '/../../tests/data/articles.csv';

    protected string $record = PublicationRecord::class;

    /**
     * Author relation, missing for every article past the second
     *
     * @return Relation
     */
    public function author(): Relation
    {
        return $this->hasOne(Author::class, 'id', 'id');
    }
}
