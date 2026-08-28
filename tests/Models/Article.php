<?php

namespace MotorORM\Tests\Models;

use MotorORM\Model;
use MotorORM\Query;

/**
 * Class Article
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property string $created_at
 */
class Article extends Model
{
    public string $table = __DIR__ . '/../../tests/data/articles.csv';

    /**
     * Something a row of this table can answer, living in the model itself
     *
     * @return string
     */
    public function shout(): string
    {
        return mb_strtoupper((string) $this->title);
    }

    /**
     * Scope without parameters
     *
     * @param Query $query
     *
     * @return Builder
     */
    public function scopeMisha(Query $query): Query
    {
        return $query->where('name', 'Миша');
    }

    /**
     * Scope with a parameter
     *
     * @param Query $query
     * @param string  $name
     *
     * @return Builder
     */
    public function scopeOfName(Query $query, string $name): Query
    {
        return $query->where('name', $name);
    }
}
