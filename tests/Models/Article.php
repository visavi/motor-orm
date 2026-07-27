<?php

namespace MotorORM\Tests\Models;

use MotorORM\Builder;

/**
 * Class Article
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $text
 * @property int $time
 */
class Article extends Builder
{
    public string $table = __DIR__ . '/../../tests/data/articles.csv';

    /**
     * Scope without parameters
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeMisha(Builder $query): Builder
    {
        return $query->where('name', 'Миша');
    }

    /**
     * Scope with a parameter
     *
     * @param Builder $query
     * @param string  $name
     *
     * @return Builder
     */
    public function scopeOfName(Builder $query, string $name): Builder
    {
        return $query->where('name', $name);
    }
}
