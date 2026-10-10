<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Timesheet;

use App\Entity\Timesheet;
use App\Entity\User;

/**
 * Weekly cut-off of timesheets, following the hierarchy:
 * - everybody enters their own week by its last day, 11:59 PM (5 Oct - 11 Oct by 11 Oct, 11:59 PM)
 * - their manager or lead can still enter or correct it during the week after (until 18 Oct, 11:59 PM)
 * - after that only an administrator can
 * The week is the person's own week (Monday to Sunday, or Sunday to Saturday when they set that).
 * It applies to new entries, changes and deletes, from every place time is entered (Log Time, Calendar Entry,
 * Bulk Entry (Week), Excel upload, the API). Leave and team events are put into timesheets by the system and
 * are not affected.
 */
final class WeeklyCutoffService
{
    /** how long the manager has after the person's own cut-off */
    public const MANAGER_DAYS = 7;

    public const SELF = 'self';
    public const MANAGER = 'manager';

    public function isAdmin(User $user): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * The first and last moment of the week a day belongs to, in the owner's time zone.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function getWeek(User $owner, \DateTimeInterface $day): array
    {
        $factory = DateTimeFactory::createByUser($owner);
        $local = \DateTimeImmutable::createFromInterface($day)->setTimezone(new \DateTimeZone($owner->getTimezone()));
        $start = \DateTimeImmutable::createFromMutable($factory->getStartOfWeek($local))->setTime(0, 0, 0);

        return [$start, $start->modify('+6 days')->setTime(23, 59, 59)];
    }

    /**
     * The last moment the actor may enter or change the owner's time for that day.
     * Null: no limit (administrators).
     */
    public function getDeadline(User $actor, User $owner, \DateTimeInterface $day): ?\DateTimeImmutable
    {
        if ($this->isAdmin($actor)) {
            return null;
        }
        [, $end] = $this->getWeek($owner, $day);

        return $actor->getId() === $owner->getId() ? $end : $end->modify('+' . self::MANAGER_DAYS . ' days');
    }

    /**
     * Null when the actor may still enter time for the owner on that day; otherwise why not:
     * SELF (own timesheet: contact your manager) or MANAGER (somebody else's: contact the admin).
     */
    public function getProblem(User $actor, User $owner, \DateTimeInterface $day, ?\DateTimeInterface $now = null): ?string
    {
        $deadline = $this->getDeadline($actor, $owner, $day);
        if ($deadline === null) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now');
        if ($now <= $deadline) {
            return null;
        }

        return $actor->getId() === $owner->getId() ? self::SELF : self::MANAGER;
    }

    public function isAllowed(User $actor, Timesheet $timesheet, ?\DateTimeInterface $now = null): bool
    {
        $begin = $timesheet->getBegin();
        $owner = $timesheet->getUser() ?? $actor;

        return $begin === null || $this->getProblem($actor, $owner, $begin, $now) === null;
    }

    /**
     * The message for the person, for example
     * "Cut-off date exceeded: the timesheet for 05-Oct-26 to 11-Oct-26 had to be entered by 11-Oct-26, 11:59 PM. ..."
     */
    public function getMessage(User $actor, User $owner, \DateTimeInterface $day): ?string
    {
        $problem = $this->getProblem($actor, $owner, $day);
        if ($problem === null) {
            return null;
        }
        [$start, $end] = $this->getWeek($owner, $day);
        $week = $start->format('d-M-y') . ' to ' . $end->format('d-M-y');

        if ($problem === self::SELF) {
            return 'Cut-off date exceeded: the timesheet for ' . $week . ' had to be entered by ' . $end->format('d-M-y') . ', 11:59 PM. '
                . 'You should enter each week\'s timesheet by the last day of that week. Please contact your manager.';
        }

        $deadline = $end->modify('+' . self::MANAGER_DAYS . ' days');

        return 'Cut-off date exceeded: you cannot enter ' . $owner->getDisplayName() . '\'s records for ' . $week
            . ' any more (managers can until ' . $deadline->format('d-M-y') . ', 11:59 PM). Please contact the admin.';
    }

    /**
     * For the forms in the browser: the first day that can still be entered, for oneself and for one's people.
     *
     * @return array{admin: bool, self_from: string, team_from: string, manager_days: int}
     */
    public function getOpenFrom(User $actor, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        [$thisWeek] = $this->getWeek($actor, $now);

        return [
            'admin' => $this->isAdmin($actor),
            // own time: this week only
            'self_from' => $thisWeek->format('Y-m-d'),
            // the people below: last week as well (its cut-off for managers is the end of this week)
            'team_from' => $thisWeek->modify('-' . self::MANAGER_DAYS . ' days')->format('Y-m-d'),
            'manager_days' => self::MANAGER_DAYS,
        ];
    }
}
