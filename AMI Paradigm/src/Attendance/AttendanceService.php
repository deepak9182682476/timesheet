<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Attendance;

use App\Entity\Attendance;
use App\Entity\User;
use App\Entity\WfhRequest;
use App\TeamEvent\LeaveTimesheetSync;
use App\TeamEvent\TeamEventTimesheetSync;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The daily attendance (physically present in the office: yes or no), entered by an administrator, and what it
 * means for the time entries of that day:
 * - attendance "yes", or an approved WFH request for the day: a normal entry
 * - a WFH request for the day waiting for approval: light cement background with a thick border
 * - attendance "no", or not entered yet: light cement background
 * Leave, holidays and team events put into the timesheet by the system are always normal, and so is everything
 * before TRACKING_FROM (the day attendance started to be kept).
 */
final class AttendanceService
{
    /** attendance is kept from this day on; earlier entries are shown as before */
    public const TRACKING_FROM = '2026-10-12';

    public const STATE_UNMARKED = 'unmarked';
    public const STATE_ABSENT = 'absent';
    public const STATE_WFH_PENDING = 'wfh_pending';
    /** worked on a weekend, a fixed holiday or a night shift, with no Extra Hours Claim for the day: light */
    public const STATE_EXTRA_MISSING = 'extra_missing';
    /** ... with an Extra Hours Claim waiting for approval: light with a thick border */
    public const STATE_EXTRA_PENDING = 'extra_pending';
    /** night shift: work before NIGHT_END or after NIGHT_START (the person's time) */
    public const NIGHT_START = 22;
    public const NIGHT_END = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Holiday\HolidayCalendar $calendar,
    ) {
    }

    /**
     * Saturday, Sunday, a fixed holiday, or a night shift (some of the work before NIGHT_END or after NIGHT_START).
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $holidays
     */
    private function isExtraWork(array $row, array $holidays): bool
    {
        $day = (string) $row['date_tz'];
        if ((int) (new \DateTimeImmutable($day))->format('N') >= 6 || isset($holidays[$day])) {
            return true;
        }
        [$begin, $end] = $this->localTimes($row);
        if ($begin === null) {
            return false;
        }
        $nightEnd = $begin->setTime(self::NIGHT_END, 0);
        $nightStart = $begin->setTime(self::NIGHT_START, 0);

        return $begin < $nightEnd || $begin >= $nightStart || ($end !== null && $end > $nightStart);
    }

    /** @param array<string, mixed> $row */
    private function startsAfterMidnight(array $row): bool
    {
        [$begin] = $this->localTimes($row);

        return $begin !== null && (int) $begin->format('G') < self::NIGHT_END;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null}
     */
    private function localTimes(array $row): array
    {
        try {
            $zone = new \DateTimeZone((string) ($row['timezone'] ?: 'UTC'));
            $utc = new \DateTimeZone('UTC');
            $begin = $row['start_time'] !== null ? (new \DateTimeImmutable((string) $row['start_time'], $utc))->setTimezone($zone) : null;
            $end = $row['end_time'] !== null ? (new \DateTimeImmutable((string) $row['end_time'], $utc))->setTimezone($zone) : null;

            return [$begin, $end];
        } catch (\Exception) {
            return [null, null];
        }
    }

    /**
     * Everybody whose attendance is kept: the active users, by name.
     *
     * @return array<User>
     */
    public function getPeople(): array
    {
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.enabled = true')
            ->getQuery()
            ->getResult();
        usort($users, static fn (User $a, User $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $users;
    }

    /**
     * @return array<int, bool> user ID => present, for those entered for that day
     */
    public function getDay(\DateTimeInterface $day): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, present FROM kimai2_attendance WHERE day = ?',
            [$day->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = (bool) $row['present'];
        }

        return $result;
    }

    /**
     * @return array<string, array<int, bool>> date (Y-m-d) => user ID => present, for those entered
     */
    public function getRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, day, present FROM kimai2_attendance WHERE day BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['day']][(int) $row['user_id']] = (bool) $row['present'];
        }

        return $result;
    }

    /**
     * Saves the attendance of a day: user ID => true (present), false (not present) or null (not entered).
     *
     * @param array<int, bool|null> $values
     * @return int how many were changed
     */
    public function saveDay(\DateTimeInterface $day, array $values, User $by): int
    {
        $existing = [];
        foreach ($this->entityManager->getRepository(Attendance::class)->findBy(['day' => new \DateTime($day->format('Y-m-d'))]) as $attendance) {
            $existing[(int) $attendance->getUser()?->getId()] = $attendance;
        }

        $changed = 0;
        foreach ($values as $userId => $present) {
            $attendance = $existing[$userId] ?? null;
            if ($present === null) {
                if ($attendance !== null) {
                    $this->entityManager->remove($attendance);
                    $changed++;
                }
                continue;
            }
            if ($attendance === null) {
                $user = $this->entityManager->find(User::class, $userId);
                if ($user === null) {
                    continue;
                }
                $attendance = new Attendance();
                $attendance->setUser($user);
                $attendance->setDay(new \DateTime($day->format('Y-m-d')));
            } elseif ($attendance->isPresent() === $present) {
                continue;
            }
            $attendance->setPresent($present);
            $attendance->setUpdatedBy($by);
            $this->entityManager->persist($attendance);
            $changed++;
        }
        $this->entityManager->flush();

        return $changed;
    }

    /**
     * How each of these time entries is shown: a STATE_* value, or nothing for a normal entry.
     *
     * @param array<int> $timesheetIds
     * @param array<int>|null $userIds only entries of these people (null: anybody's)
     * @return array<int, string> timesheet ID => state
     */
    public function getEntryStates(array $timesheetIds, ?array $userIds): array
    {
        $timesheetIds = array_values(array_unique(array_filter(array_map('intval', $timesheetIds))));
        if ($timesheetIds === [] || $userIds === []) {
            return [];
        }
        $connection = $this->entityManager->getConnection();
        // $sql = 'SELECT t.id, t.user, t.date_tz FROM kimai2_timesheet t
        $sql = 'SELECT t.id, t.user, t.date_tz, t.start_time, t.end_time, t.timezone FROM kimai2_timesheet t
                WHERE t.id IN (' . implode(',', $timesheetIds) . ') AND t.date_tz >= ?
                AND NOT EXISTS (SELECT 1 FROM kimai2_timesheet_meta m WHERE m.timesheet_id = t.id AND m.name IN (?, ?) AND m.value IS NOT NULL AND m.value <> \'\')';
        if ($userIds !== null) {
            $sql .= ' AND t.user IN (' . implode(',', array_map('intval', $userIds)) . ')';
        }
        $entries = $connection->fetchAllAssociative($sql, [self::TRACKING_FROM, LeaveTimesheetSync::META_FIELD, TeamEventTimesheetSync::META_FIELD]);
        if ($entries === []) {
            return [];
        }

        $people = array_values(array_unique(array_map(static fn (array $row) => (int) $row['user'], $entries)));
        $days = array_map(static fn (array $row) => (string) $row['date_tz'], $entries);
        $first = min($days);
        $last = max($days);
        $in = implode(',', $people);

        $present = [];
        foreach ($connection->fetchAllAssociative('SELECT user_id, day, present FROM kimai2_attendance WHERE user_id IN (' . $in . ') AND day BETWEEN ? AND ?', [$first, $last]) as $row) {
            $present[(int) $row['user_id']][(string) $row['day']] = (bool) $row['present'];
        }
        $wfh = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT user_id, start_date, end_date, status FROM kimai2_wfh_requests WHERE user_id IN (' . $in . ') AND status <> ? AND end_date >= ? AND start_date <= ?',
            [WfhRequest::STATUS_REJECTED, $first, $last]
        ) as $row) {
            $wfh[(int) $row['user_id']][] = $row;
        }

        // Extra Hours Claims (not rejected) of these people: date worked => approved / pending
        $claims = [];
        $before = (new \DateTimeImmutable($first))->modify('-1 day')->format('Y-m-d');
        foreach ($connection->fetchAllAssociative(
            'SELECT user_id, work_date, status FROM kimai2_additional_hours WHERE user_id IN (' . $in . ') AND status <> ? AND work_date BETWEEN ? AND ?',
            [\App\Entity\AdditionalHours::STATUS_REJECTED, $before, $last]
        ) as $row) {
            $date = (string) $row['work_date'];
            if (($claims[(int) $row['user_id']][$date] ?? null) !== \App\Entity\AdditionalHours::STATUS_APPROVED) {
                $claims[(int) $row['user_id']][$date] = (string) $row['status'];
            }
        }
        // the fixed holidays of each person's office (without an office: the holidays of every office)
        $holidays = [];
        foreach ($people as $personId) {
            $person = $this->entityManager->find(User::class, $personId);
            $office = $person !== null ? \App\Holiday\HolidayCalendar::getUserLocation($person) : null;
            $holidays[$personId] = $office !== null ? $this->calendar->getHolidays($office) : $this->calendar->getHolidaysEverywhere();
        }

        $states = [];
        foreach ($entries as $row) {
            $user = (int) $row['user'];
            $day = (string) $row['date_tz'];

            // Work on a weekend, a fixed holiday or a night shift counts once the Extra Hours Claim for the day is
            // approved by the immediate supervisor; the attendance does not matter for it.
            $extra = $this->isExtraWork($row, $holidays[$user] ?? []);
            if ($extra) {
                $claim = $claims[$user][$day] ?? null;
                // work after midnight belongs to the night shift claimed for the day before
                if ($claim !== \App\Entity\AdditionalHours::STATUS_APPROVED && $this->startsAfterMidnight($row)) {
                    $previous = (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
                    $claim = ($claims[$user][$previous] ?? null) ?? $claim;
                }
                if ($claim === \App\Entity\AdditionalHours::STATUS_APPROVED) {
                    continue;
                }
                $states[(int) $row['id']] = $claim !== null ? self::STATE_EXTRA_PENDING : self::STATE_EXTRA_MISSING;
                continue;
            }

            if (($present[$user][$day] ?? null) === true) {
                continue;
            }
            $request = null;
            foreach ($wfh[$user] ?? [] as $candidate) {
                if ($day >= $candidate['start_date'] && $day <= $candidate['end_date']) {
                    // an approved request wins over a waiting one
                    if ($request === null || $candidate['status'] === WfhRequest::STATUS_APPROVED) {
                        $request = $candidate;
                    }
                }
            }
            if ($request !== null && $request['status'] === WfhRequest::STATUS_APPROVED) {
                continue;
            }
            if ($request !== null) {
                $states[(int) $row['id']] = self::STATE_WFH_PENDING;
            } else {
                $states[(int) $row['id']] = isset($present[$user][$day]) ? self::STATE_ABSENT : self::STATE_UNMARKED;
            }
        }

        return $states;
    }
}
