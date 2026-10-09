<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

/**
 * No new time entry on a day the person is on approved leave: the leave already filled that day
 * (see App\TeamEvent\LeaveTimesheetSync). The leave has to be cancelled first.
 */
final class TimesheetApprovedLeave extends TimesheetConstraint
{
    public const APPROVED_LEAVE_ERROR = 'ami-timesheet-approved-leave-01';

    protected const ERROR_NAMES = [
        self::APPROVED_LEAVE_ERROR => 'The person is on approved leave on this day.',
    ];

    // public string $message = 'You are on approved leave on {{ date }} ({{ leave }}). Cancel that leave on the Leave page before logging time for this day.';
    public string $message = 'You are on approved leave on {{ date }} ({{ leave }}). If you worked that day, tick "I worked during my leave" and give the reason.';
    // public string $messageOther = '{{ name }} is on approved leave on {{ date }} ({{ leave }}). The leave has to be cancelled before time can be logged for this day.';
    public string $messageOther = '{{ name }} is on approved leave on {{ date }} ({{ leave }}). If they worked that day, give the reason.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
