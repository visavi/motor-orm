<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Kind of a relation between two tables
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
enum RelationType
{
    case HasOne;
    case HasMany;
    case HasManyThrough;
}
