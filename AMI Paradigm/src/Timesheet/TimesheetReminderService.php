<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Timesheet;

use App\Entity\TeamEvent;
use App\Entity\User;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\TeamEventService;
use App\WorkModel\WorkModelService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reminders on the last day to enter time (see WeeklyCutoffService):
 * - the person: on the Monday their last week closes (11:59 PM), if working days of that week have no time
 * - their lead or manager: on the Monday the manager's extra week closes, for each of their people with
 *   working days of that week still empty
 * A working day is Monday to Friday, not a fixed holiday of the person's office and not a day of approved
 * (full-day) leave. The reminders are worked out on every page, so they are gone after 11:59 PM by themselves.
 */
final class TimesheetReminderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WeeklyCutoffService $cutoff,
        private readonly TeamEventService $events,
        private readonly HolidayCalendar $calendar,
        private readonly WorkModelService $models,
    ) {
    }

    /**
     * The working days from $start to $end on which the person logged no time.
     *
     * @return array<string, string> label ("Mon 12-Oct") keyed by date (Y-m-d)
     */
    public function getMissingDays(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $location = HolidayCalendar::getUserLocation($user);
        $holidays = $location !== null ? $this->calendar->getHolidays($location) : $this->calendar->getHolidaysEverywhere();

        $leave = [];
        try {
            foreach ($this->events->getMyLeave($user) as $item) {
                if ($item->getStatus() !== TeamEvent::STATUS_APPROVED || $item->isPartDay()) {
                    continue;
                }
                foreach ($this->events->getLeaveDates($item) as $date) {
                    $leave[$date] = true;
                }
            }
        } catch (\Throwable) {
        }

        $logged = [];
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT date_tz FROM kimai2_timesheet WHERE user = ? AND date_tz BETWEEN ? AND ?',
            [$user->getId(), $start->format('Y-m-d'), $end->format('Y-m-d')]
        );
        foreach ($rows as $date) {
            $logged[substr((string) $date, 0, 10)] = true;
        }

        // nobody is reminded of days before they joined
        $registered = $user->getRegisteredAt();
        $joined = $registered !== null ? $registered->format('Y-m-d') : null;

        $missing = [];
        for ($day = $start->setTime(0, 0); $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ((int) $day->format('N') > 5 || isset($holidays[$date]) || isset($leave[$date]) || isset($logged[$date])) {
                continue;
            }
            if ($joined !== null && $date < $joined) {
                continue;
            }
            $missing[$date] = $day->format('D d-M');
        }

        return $missing;
    }

    /**
     * The person's own reminder: only on the day their last week closes, and only when days are missing.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, deadline: \DateTimeImmutable, days: array<string, string>}|null
     */
    public function getOwnReminder(User $user, ?\DateTimeInterface $now = null): ?array
    {
        // the system administrator does not log time
        if ($user->isSuperAdmin() || !$user->isEnabled()) {
            return null;
        }
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone($user->getTimezone()));
        [$thisWeek] = $this->cutoff->getWeek($user, $now);
        $from = $thisWeek->modify('-7 days');
        $to = $from->modify('+6 days')->setTime(23, 59, 59);
        $deadline = $to->modify('+' . WeeklyCutoffService::GRACE_DAYS . ' days');
        if ($deadline->format('Y-m-d') !== $now->format('Y-m-d')) {
            return null;
        }
        $days = $this->getMissingDays($user, $from, $to);

        return $days === [] ? null : ['from' => $from, 'to' => $to, 'deadline' => $deadline, 'days' => $days];
    }

    /**
     * The lead's or manager's reminder: only on the day their extra week for their people closes,
     * listing each person of theirs with days still missing.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, deadline: \DateTimeImmutable, people: array<int, array{user: User, days: array<string, string>}>}|null
     */
    public function getTeamReminder(User $user, ?\DateTimeInterface $now = null): ?array
    {
        // leads and managers only: the system administrator can always enter time for anybody
        if (!$user->isEnabled() || $user->isSuperAdmin()) {
            return null;
        }
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone($user->getTimezone()));
        [$thisWeek] = $this->cutoff->getWeek($user, $now);
        $from = $thisWeek->modify('-14 days');
        $to = $from->modify('+6 days')->setTime(23, 59, 59);
        $deadline = $to->modify('+' . (WeeklyCutoffService::GRACE_DAYS + WeeklyCutoffService::MANAGER_DAYS) . ' days');
        if ($deadline->format('Y-m-d') !== $now->format('Y-m-d')) {
            return null;
        }

        $people = [];
        foreach ($this->models->getAssignableUsers($user) as $person) {
            if ($person->getId() === $user->getId() || !$person->isEnabled() || $person->isSuperAdmin()) {
                continue;
            }
            $days = $this->getMissingDays($person, $from, $to);
            if ($days !== []) {
                $people[(int) $person->getId()] = ['user' => $person, 'days' => $days];
            }
        }
        if ($people === []) {
            return null;
        }
        uasort($people, static fn (array $a, array $b) => strcasecmp($a['user']->getDisplayName(), $b['user']->getDisplayName()));

        return ['from' => $from, 'to' => $to, 'deadline' => $deadline, 'people' => array_values($people)];
    }
}
