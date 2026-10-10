<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

/**
 * Weekly cut-off: own time by the last day of the week, the manager's a week later, then only an admin
 * (see App\Timesheet\WeeklyCutoffService).
 */
final class TimesheetWeeklyCutoff extends TimesheetConstraint
{
    public const CUTOFF_ERROR = 'ami-timesheet-weekly-cutoff-01';

    protected const ERROR_NAMES = [
        self::CUTOFF_ERROR => 'The cut-off date of this week has passed.',
    ];

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
