<?php

declare(strict_types=1);

namespace MotorORM;

use InvalidArgumentException;

/**
 * The conditions a query puts on a row
 *
 * Conditions are collected while the query is built and asked of every row
 * while it is read. What they are asked of is a raw row of the file, so a
 * column name is looked up in the table and nothing is built per row
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class Conditions
{
    /**
     * Collected conditions, grouped under the operator that joins them
     *
     * A nested group sits under a numeric key, and its own keys carry the
     * operators the group is joined by inside
     */
    private array $where = [];

    /**
     * Whether nothing has been asked of the rows
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return ! $this->where;
    }

    /**
     * Compare a column with a value
     *
     * @param string $operator  and or or
     * @param string $field
     * @param mixed  $condition the comparison
     * @param mixed  $value
     *
     * @return void
     */
    public function compare(string $operator, string $field, mixed $condition, mixed $value): void
    {
        $this->where[$operator][] = [
            'field'     => $field,
            'condition' => $this->comparison($condition),
            'value'     => (string) $value,
        ];
    }

    /**
     * Match a column against a pattern
     *
     * @param string $operator
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     * @param bool   $not
     *
     * @return void
     */
    public function pattern(string $operator, string $field, string $value, bool $caseSensitive, bool $not): void
    {
        $this->where[$operator][] = [
            'field'         => $field,
            'condition'     => $not ? 'not_like' : 'like',
            'value'         => $value,
            'caseSensitive' => $caseSensitive,
        ];
    }

    /**
     * Look a column up in a set of values
     *
     * @param string $operator
     * @param string $field
     * @param array  $values
     * @param bool   $not
     *
     * @return void
     */
    public function set(string $operator, string $field, array $values, bool $not): void
    {
        $this->where[$operator][] = [
            'field'     => $field,
            'condition' => $not ? 'not_in' : 'in',
            /* Flipped, so a row asks isset instead of walking the set */
            'value'     => array_flip($values),
        ];
    }

    /**
     * Add conditions collected apart as one group
     *
     * @param string $operator how the group is joined to what came before it
     * @param self   $group
     *
     * @return void
     */
    public function group(string $operator, self $group): void
    {
        $this->where[$operator][] = $group->where;
    }

    /**
     * Whether a row satisfies the conditions
     *
     * @param array $record raw row of the file
     * @param Table $table  where a column name becomes a position
     *
     * @return bool
     */
    public function match(array $record, Table $table): bool
    {
        return $this->checker($this->where, $record, $table);
    }

    /**
     * The comparison a condition asks for
     *
     * A misspelled operator used to fall through to equality and quietly
     * return nothing, so only these are accepted. Patterns and sets have
     * methods of their own
     *
     * @param mixed $condition
     *
     * @return string
     */
    private function comparison(mixed $condition): string
    {
        return match ($condition) {
            '=', '!=', '<>', '>', '>=', '<', '<=' => $condition,
            default => throw new InvalidArgumentException(
                sprintf('%s() unknown operator "%s"', __METHOD__, is_scalar($condition) ? $condition : get_debug_type($condition))
            ),
        };
    }

    /**
     * Walk a group of conditions
     *
     * @param array  $wheres
     * @param array  $record
     * @param Table  $table
     * @param string $operator how the conditions of this group are joined
     *
     * @return bool
     */
    private function checker(array $wheres, array $record, Table $table, string $operator = 'or'): bool
    {
        $isOr = $operator === 'or';

        foreach ($wheres as $key => $where) {
            if (isset($where['field'])) {
                $field  = $record[$table->keyOf($where['field'])];
                $result = $this->condition($field, $where['condition'], $where['value'], $where['caseSensitive'] ?? false);
            } else {
                /* A nested group is stored under a numeric key, its own keys carry the operators */
                $result = $this->checker($where, $record, $table, is_string($key) ? $key : 'or');
            }

            /* A true settles an or, a false settles an and */
            if ($isOr === $result) {
                return $result;
            }
        }

        return ! $isOr;
    }

    /**
     * Whether one value stands to another the way the condition says
     *
     * @param mixed  $field
     * @param string $condition
     * @param mixed  $value
     * @param bool   $caseSensitive
     *
     * @return bool
     */
    private function condition(mixed $field, string $condition, mixed $value = null, bool $caseSensitive = false): bool
    {
        return match ($condition) {
            '!=', '<>' => $field !== $value,
            '>=' => $field >= $value,
            '<=' => $field <= $value,
            '>' => $field > $value,
            '<' => $field < $value,
            'in' => isset($value[$field]),
            'not_in' => ! isset($value[$field]),
            'like' => self::matches((string) $field, $value, $caseSensitive),
            'not_like' => ! self::matches((string) $field, $value, $caseSensitive),
            default => $field === $value,
        };
    }

    /**
     * Whether a value matches a pattern
     *
     * A leading or trailing % says the value may go on there, a pattern
     * without either has to match the whole value, as sql like does
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     *
     * @return bool
     */
    private static function matches(string $field, string $value, bool $caseSensitive): bool
    {
        if (! $caseSensitive) {
            /* Lowering both sides beats a case-insensitive search on each of them */
            $field = mb_strtolower($field, 'UTF-8');
            $value = mb_strtolower($value, 'UTF-8');
        }

        $left  = str_starts_with($value, '%');
        $right = str_ends_with($value, '%');

        if (! $left && ! $right) {
            return $field === $value;
        }

        $needle = trim($value, '%');

        return match (true) {
            $left && $right => str_contains($field, $needle),
            $left           => str_ends_with($field, $needle),
            default         => str_starts_with($field, $needle),
        };
    }
}
