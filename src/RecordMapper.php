<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;
use JsonException;
use UnexpectedValueException;

/**
 * Translator between a row of the file and the values of a record
 *
 * A table stores strings and nothing else. Coming in, a row is named after the
 * columns and turned into the types the model declared; going out, whatever
 * the values are is turned back into strings the file can hold
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class RecordMapper
{
    /**
     * @param Model $model the table the values belong to
     * @param Table $table where the column names are read from
     */
    public function __construct(
        private readonly Model $model,
        private readonly Table $table,
    ) {}

    /**
     * Turn one row into the values of a record
     *
     * @param array $record
     *
     * @return array column name => value
     */
    public function read(array $record): array
    {
        return $this->reader()($record);
    }

    /**
     * A reader for a whole result
     *
     * The column names, the declared casts and the primary key are the same
     * for every row, so they are resolved once and the rows go through what
     * comes back. Reading them per row would cost a lookup a row
     *
     * @return Closure array of values => column name => value
     */
    public function reader(): Closure
    {
        $headers    = $this->table->headers();
        $fieldCount = count($headers);
        $casts      = $this->model->getCasts();
        $key        = $headers[0] ?? null;

        /* The key is cast by the orm that generated it, unless the model says otherwise */
        $primary = $key !== null && ! isset($casts[$key]) ? $key : null;

        return function (array $record) use ($headers, $fieldCount, $casts, $primary): array {
            if (count($record) !== $fieldCount) {
                $record = array_slice(array_pad($record, $fieldCount, null), 0, $fieldCount);
            }

            $record = array_combine($headers, $record);

            foreach ($record as $field => $value) {
                if ($value === '') {
                    $record[$field] = null;
                } elseif (isset($casts[$field])) {
                    try {
                        $record[$field] = $this->cast($casts[$field], $value);
                    } catch (JsonException $exception) {
                        throw new UnexpectedValueException(
                            sprintf('%s() column "%s" does not hold the json it was cast to: %s', __METHOD__, $field, $exception->getMessage()),
                            previous: $exception
                        );
                    }
                }
            }

            /* A generated key is a number and reads back as one, a key that is not stays a string */
            if ($primary !== null && is_numeric($record[$primary])) {
                $record[$primary] = (int) $record[$primary];
            }

            return $record;
        };
    }

    /**
     * Turn the values of a record into a row the file can hold
     *
     * @param array $values
     *
     * @return array
     */
    public function write(array $values): array
    {
        foreach ($values as $field => $value) {
            if ($value === false) {
                $values[$field] = '0';

                continue;
            }

            if (is_array($value) || is_object($value)) {
                try {
                    $values[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    /* A value that cannot be written is worth an error, an empty column is not */
                    throw new UnexpectedValueException(
                        sprintf('%s() column "%s" holds a value json cannot carry: %s', __METHOD__, $field, $exception->getMessage()),
                        previous: $exception
                    );
                }

                continue;
            }

            $values[$field] = (string) $value;
        }

        return $values;
    }

    /**
     * Give a value the type the model declared for its column
     *
     * @param string $cast
     * @param mixed  $value
     *
     * @return mixed
     */
    private function cast(string $cast, mixed $value): mixed
    {
        return match ($cast) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            /* A column that does not hold the json it was cast to is a broken table, not a null */
            'object' => json_decode($value, false, 512, JSON_THROW_ON_ERROR),
            'array' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}
