<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Direction of a sort
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
