<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

/**
 * A time entry on a project with a model (Agile, Waterfall, Pre-sales) has to pick an item
 * of that project down to the activity, and the item has to be assigned to the person.
 */
final class TimesheetWorkItem extends TimesheetConstraint
{
    public const WORK_ITEM_ERROR = 'ami-timesheet-work-item-01';

    protected const ERROR_NAMES = [
        self::WORK_ITEM_ERROR => 'The item picked for this project is missing or not allowed.',
    ];

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
