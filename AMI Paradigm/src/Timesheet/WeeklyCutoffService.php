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
 * - everybody enters their own week by the day after its last day, 11:59 PM (5 Oct - 11 Oct by Monday 12 Oct, 11:59 PM)
 * - their manager or lead can still enter or correct it for a week after that (until 19 Oct, 11:59 PM)
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
    /** the person's own cut-off is this many days after the last day of the week: the Monday after, 11:59 PM */
    public const GRACE_DAYS = 1;

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
        // return $actor->getId() === $owner->getId() ? $end : $end->modify('+' . self::MANAGER_DAYS . ' days');
        $own = $end->modify('+' . self::GRACE_DAYS . ' days');

        return $actor->getId() === $owner->getId() ? $own : $own->modify('+' . self::MANAGER_DAYS . ' days');
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

        $own = $end->modify('+' . self::GRACE_DAYS . ' days');
        // own time: the manager can still enter it during the week after one's own cut-off; after that only the admin
        $managerUntil = $own->modify('+' . self::MANAGER_DAYS . ' days');
        if ($problem === self::SELF && new \DateTimeImmutable('now') > $managerUntil) {
            return 'Cut-off date exceeded: the timesheet for ' . $week . ' had to be entered by ' . $own->format('D d-M-y') . ', 11:59 PM, '
                . 'and your manager could enter it until ' . $managerUntil->format('D d-M-y') . ', 11:59 PM. Please contact the admin.';
        }
        if ($problem === self::SELF) {
            // return 'Cut-off date exceeded: the timesheet for ' . $week . ' had to be entered by ' . $end->format('d-M-y') . ', 11:59 PM. '
            //     . 'You should enter each week\'s timesheet by the last day of that week. Please contact your manager.';
            return 'Cut-off date exceeded: the timesheet for ' . $week . ' had to be entered by ' . $own->format('D d-M-y') . ', 11:59 PM. '
                . 'You should enter each week\'s timesheet by the Monday after it (11:59 PM). Please contact your manager.';
        }

        // $deadline = $end->modify('+' . self::MANAGER_DAYS . ' days');
        $deadline = $own->modify('+' . self::MANAGER_DAYS . ' days');

        return 'Cut-off date exceeded: you cannot enter ' . $owner->getDisplayName() . '\'s records for ' . $week
            . ' any more (managers can until ' . $deadline->format('d-M-y') . ', 11:59 PM). Please contact the admin.';
    }

    /**
     * No time for future days: up to today (in the owner's time zone) only, for everybody.
     * Null when the day is today or earlier; otherwise the message.
     */
    public function getFutureMessage(User $owner, \DateTimeInterface $day, ?\DateTimeInterface $now = null): ?string
    {
        $zone = new \DateTimeZone($owner->getTimezone());
        $now ??= new \DateTimeImmutable('now');
        $today = \DateTimeImmutable::createFromInterface($now)->setTimezone($zone)->format('Y-m-d');
        $local = \DateTimeImmutable::createFromInterface($day)->setTimezone($zone);
        if ($local->format('Y-m-d') <= $today) {
            return null;
        }

        return 'Future date not allowed: you cannot enter time for ' . $local->format('D d-M-y') . ' yet. '
            . 'Time can be entered up to today (' . \DateTimeImmutable::createFromInterface($now)->setTimezone($zone)->format('D d-M-y') . ').';
    }

    /**
     * For the forms in the browser: the first day that can still be entered, for oneself and for one's people.
     *
     * @return array{admin: bool, self_from: string, team_from: string, manager_days: int, until: string}
     */
    public function getOpenFrom(User $actor, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        [$thisWeek] = $this->getWeek($actor, $now);
        // the first day of the oldest week whose deadline has not passed yet
        $openFrom = function (int $extraDays) use ($thisWeek, $now): string {
            $week = $thisWeek;
            for ($i = 0; $i < 4; $i++) {
                $previous = $week->modify('-7 days');
                $deadline = $previous->modify('+6 days')->setTime(23, 59, 59)->modify('+' . (self::GRACE_DAYS + $extraDays) . ' days');
                if ($now > $deadline) {
                    break;
                }
                $week = $previous;
            }

            return $week->format('Y-m-d');
        };

        return [
            'admin' => $this->isAdmin($actor),
            // own time: this week, and last week until the Monday after it (11:59 PM)
            // 'self_from' => $thisWeek->format('Y-m-d'),
            'self_from' => $openFrom(0),
            // the people below: a week longer
            // 'team_from' => $thisWeek->modify('-' . self::MANAGER_DAYS . ' days')->format('Y-m-d'),
            'team_from' => $openFrom(self::MANAGER_DAYS),
            'manager_days' => self::MANAGER_DAYS,
            // the last day that can be entered: today (no future days, for everybody)
            'until' => \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone($actor->getTimezone()))->format('Y-m-d'),
        ];
    }
}
