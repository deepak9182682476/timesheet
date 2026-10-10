<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\TeamEvent;

use App\Entity\Team;
use App\Entity\TeamEvent;
use App\Entity\User;
use App\Holiday\HolidayCalendar;
use App\Task\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Loads events (team outings, holidays, leave ...) and decides who may add them.
 *
 * Adding follows the same rule as assigning tasks: administrators, and anyone who
 * manages somebody. Everyone sees the events that apply to them.
 */
final class TeamEventService
{
    /** Leave days a person gets per calendar year, unless their profile sets "holidays per year" */
    public const LEAVE_DAYS_PER_YEAR = 20;
    /** How far below zero the balance may go; a request that would take it lower is refused */
    public const LEAVE_OVERDRAW_DAYS = 5;
    /** How many optional holidays a person can take per calendar year */
    public const OPTIONAL_HOLIDAYS_PER_YEAR = 2;
    /** Leave types that do not use up the yearly leave days */
    // public const LEAVE_TYPES_NOT_COUNTED = ['Comp off', 'Optional holiday'];
    public const LEAVE_TYPES_NOT_COUNTED = [TeamEvent::COMP_OFF, 'Optional holiday'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskService $tasks,
        private readonly HolidayCalendar $calendar
    )
    {
    }

    public function canManage(User $user): bool
    {
        return $this->tasks->canAssign($user);
    }

    public function isAdmin(User $user): bool
    {
        return $this->tasks->isAdmin($user);
    }

    /**
     * The creator and administrators can change or delete an event.
     */
    public function canEdit(User $user, TeamEvent $event): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        // leave a person applied for is handled on the Leave page (apply, approve, cancel), not edited here
        if ($event->isLeaveRequest()) {
            return false;
        }

        return $event->getCreatedBy() !== null && $event->getCreatedBy()->getId() === $user->getId();
    }

    /**
     * Teams this user can create an event for: all of them for administrators, otherwise their own teams.
     *
     * @return array<Team>
     */
    public function getTeams(User $user): array
    {
        if ($this->isAdmin($user)) {
            $teams = $this->entityManager->createQueryBuilder()
                ->select('t')
                ->from(Team::class, 't')
                ->orderBy('t.name', 'ASC')
                ->getQuery()
                ->getResult();
        } else {
            $teams = $user->getTeams();
            usort($teams, static fn (Team $a, Team $b) => strcasecmp((string) $a->getName(), (string) $b->getName()));
        }

        return $teams;
    }

    /**
     * People this user can create an event for: themselves and the people they manage.
     *
     * @return array<User>
     */
    public function getPeople(User $user): array
    {
        return $this->tasks->getAssignableUsers($user);
    }

    /**
     * Events that apply to this user and overlap the given days (both inclusive).
     *
     * @return array<TeamEvent>
     */
    public function getEventsForUser(User $user, \DateTimeInterface $from, \DateTimeInterface $to, ?int $limit = null, bool $personal = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.startDate <= :to')
            ->andWhere('e.endDate >= :from')
            ->andWhere('e.status = :approved')
            ->setParameter('approved', TeamEvent::STATUS_APPROVED)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('e.startDate', 'ASC')
            ->addOrderBy('e.title', 'ASC');

        $this->restrictToUser($qb, $user, $personal);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * True once an event is over: after its last day, or on that day once its end time (or, without one, its start
     * time) has passed, in the person's time zone. Leave lasts its whole day. Nothing is deleted: past events and
     * leave stay in the database (calendar, reports); they are only no longer listed as upcoming.
     */
    public function isOver(TeamEvent $event, User $user): bool
    {
        $timezone = new \DateTimeZone($user->getTimezone());
        $now = new \DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');
        $lastDay = $event->getEndDate()?->format('Y-m-d');
        if ($lastDay === null || $lastDay > $today) {
            return false;
        }
        if ($lastDay < $today) {
            return true;
        }
        if ($event->getType() === TeamEvent::TYPE_LEAVE) {
            return false;
        }
        $time = $event->getEndTime() ?? $event->getStartTime();
        if ($time === null) {
            return false;
        }

        return $now->format('H:i:s') > $time->format('H:i:s');
    }

    /**
     * The events and leave from today on that are not over yet (see isOver).
     *
     * @return array<TeamEvent>
     */
    public function getUpcomingForUser(User $user, \DateTimeInterface $to, ?int $limit = null, bool $personal = false): array
    {
        $events = array_values(array_filter(
            $this->getEventsForUser($user, new \DateTime('today'), $to, $limit !== null ? $limit + 10 : null, $personal),
            fn (TeamEvent $event) => !$this->isOver($event, $user)
        ));

        return $limit !== null ? \array_slice($events, 0, $limit) : $events;
    }

    /**
     * Approved leave of the people in this user's teams (the user included) that is not over yet. It is listed
     * from the moment it is approved until the end of its last day, so leave for tomorrow shows until tomorrow
     * 11:59 pm and is gone after that. Leave waiting for a decision and rejected leave are not listed;
     * cancelled leave does not exist any more.
     *
     * @return array<TeamEvent>
     */
    public function getTeammateLeave(User $user): array
    {
        $people = [(int) $user->getId() => $user];
        foreach ($user->getTeams() as $team) {
            foreach ($team->getUsers() as $member) {
                if ($member->isEnabled()) {
                    $people[(int) $member->getId()] = $member;
                }
            }
        }

        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.type = :leave')
            ->andWhere('e.status = :approved')
            ->andWhere('e.endDate >= :today')
            ->andWhere('e.user IN (:people)')
            ->setParameter('leave', TeamEvent::TYPE_LEAVE)
            // earlier leave waiting for approval was listed too: [TeamEvent::STATUS_PENDING, TeamEvent::STATUS_APPROVED]
            ->setParameter('approved', TeamEvent::STATUS_APPROVED)
            ->setParameter('today', (new \DateTime('today'))->format('Y-m-d'))
            ->setParameter('people', array_keys($people))
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events of the past that applied to this user, newest first.
     *
     * @return array<TeamEvent>
     */
    public function getPastEventsForUser(User $user, int $limit = 15): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.endDate < :today')
            ->andWhere('e.status = :approved')
            ->setParameter('approved', TeamEvent::STATUS_APPROVED)
            ->setParameter('today', (new \DateTime())->format('Y-m-d'))
            ->orderBy('e.startDate', 'DESC')
            ->setMaxResults($limit);

        $this->restrictToUser($qb, $user);

        return $qb->getQuery()->getResult();
    }

    /**
     * Event titles per day, for labelling the days of a chart.
     *
     * @return array<string, array<string>> keyed by date as Y-m-d
     */
    public function getTitlesByDay(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = [];
        // a person's own dashboard: only what concerns them, whatever their role
        foreach ($this->getEventsForUser($user, $from, $to, null, true) as $event) {
            $day = \DateTime::createFromInterface(max($event->getStartDate(), $from));
            $day->setTime(0, 0);
            $last = min($event->getEndDate(), $to)->format('Y-m-d');
            $guard = 0;
            while ($day->format('Y-m-d') <= $last && $guard++ < 370) {
                $days[$day->format('Y-m-d')][] = (string) $event->getTitle();
                $day->modify('+1 day');
            }
        }

        return $days;
    }

    // ---------------------------------------------------------------------
    // Leave: a person applies for themselves, a manager above them decides
    // ---------------------------------------------------------------------

    /**
     * Creates the leave request and sends it to the manager, with two exceptions that are approved straight away:
     * - an optional holiday, because the date was already checked against the holiday calendar
     * - leave of an administrator, who has nobody above them
     */
    public function applyForLeave(User $user, TeamEvent $leave): void
    {
        $leave->setType(TeamEvent::TYPE_LEAVE);
        $leave->setUser($user);
        $leave->setTeam(null);
        $leave->setCreatedBy($user);
        if ($leave->getEndDate() === null || $leave->getEndDate() < $leave->getStartDate()) {
            $leave->setEndDate(clone $leave->getStartDate());
        }

        if ($leave->getTitle() === 'Optional holiday') {
            $leave->setStatus(TeamEvent::STATUS_APPROVED);
        } elseif ($this->isAdmin($user)) {
            $leave->setStatus(TeamEvent::STATUS_APPROVED);
            $leave->setDecidedBy($user);
        } else {
            $leave->setStatus(TeamEvent::STATUS_PENDING);
        }

        $this->save($leave);
    }

    /**
     * Yearly leave days of this person: the "holidays per year" from their profile if set, otherwise the default.
     */
    public function getLeaveAllowance(User $user): float
    {
        $own = $user->getHolidaysPerYear();

        return $own > 0 ? $own : (float) self::LEAVE_DAYS_PER_YEAR;
    }

    /**
     * Working days of leave already used in a calendar year. Requests still waiting count as well,
     * so that nobody can queue up more than they have; rejected ones do not.
     */
    public function getLeaveUsed(User $user, int $year): int
    {
        $used = 0;
        foreach ($this->getMyLeave($user) as $leave) {
            if ($leave->getStatus() === TeamEvent::STATUS_REJECTED) {
                continue;
            }
            if ((int) $leave->getStartDate()->format('Y') !== $year) {
                continue;
            }
            if (!$this->countsAgainstBalance($leave)) {
                continue;
            }
            $used += $this->getLeaveDays($leave);
        }

        return $used;
    }

    /**
     * The days a leave really costs: Monday to Friday inside its dates, leaving out
     * - days that are a holiday at every office,
     * - days the person already has as an optional holiday, and
     * - days already covered by leave they applied for earlier.
     * So leave from the 16th to the 22nd, with a leave or optional holiday already on the 20th, is four days, not five.
     */
    public function getLeaveDays(TeamEvent $leave): int
    {
        if ($leave->getStartDate() === null || $leave->getEndDate() === null) {
            return 0;
        }

        // an optional holiday is itself one day
        if ($leave->getTitle() === 'Optional holiday') {
            return $leave->getWorkingDays();
        }

        $skip = $this->calendar->getHolidaysEverywhere();
        if ($leave->getUser() !== null) {
            foreach ($this->getOptionalHolidayDates($leave->getUser()) as $date) {
                $skip[$date] = true;
            }
            foreach ($this->getDatesCoveredByEarlierLeave($leave) as $date) {
                $skip[$date] = true;
            }
        }

        $days = 0;
        $day = clone $leave->getStartDate();
        $last = $leave->getEndDate()->format('Y-m-d');
        $guard = 0;
        while ($day->format('Y-m-d') <= $last && $guard++ < 800) {
            if ((int) $day->format('N') <= 5 && !isset($skip[$day->format('Y-m-d')])) {
                $days++;
            }
            $day->modify('+1 day');
        }

        return $days;
    }

    /**
     * The dates a leave costs, as Y-m-d: the same days getLeaveDays() counts.
     *
     * @return array<string>
     */
    public function getLeaveDates(TeamEvent $leave): array
    {
        if ($leave->getStartDate() === null || $leave->getEndDate() === null) {
            return [];
        }

        $skip = [];
        if ($leave->getTitle() !== 'Optional holiday') {
            $skip = $this->calendar->getHolidaysEverywhere();
            if ($leave->getUser() !== null) {
                foreach ($this->getOptionalHolidayDates($leave->getUser()) as $date) {
                    $skip[$date] = true;
                }
                foreach ($this->getDatesCoveredByEarlierLeave($leave) as $date) {
                    $skip[$date] = true;
                }
            }
        }

        $dates = [];
        $day = clone $leave->getStartDate();
        $last = $leave->getEndDate()->format('Y-m-d');
        $guard = 0;
        while ($day->format('Y-m-d') <= $last && $guard++ < 800) {
            if ((int) $day->format('N') <= 5 && !isset($skip[$day->format('Y-m-d')])) {
                $dates[] = $day->format('Y-m-d');
            }
            $day->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Every date covered by the person's other leave that was applied for before this one
     * (waiting or approved). For a request that is not saved yet, that is all their leave.
     *
     * @return array<string> dates as Y-m-d
     */
    public function getDatesCoveredByEarlierLeave(TeamEvent $leave): array
    {
        if ($leave->getUser() === null) {
            return [];
        }

        $dates = [];
        foreach ($this->getMyLeave($leave->getUser()) as $other) {
            if ($other->getStatus() === TeamEvent::STATUS_REJECTED || $other->getId() === null) {
                continue;
            }
            if ($leave->getId() !== null && $other->getId() >= $leave->getId()) {
                continue;
            }

            $day = clone $other->getStartDate();
            $last = $other->getEndDate()->format('Y-m-d');
            $guard = 0;
            while ($day->format('Y-m-d') <= $last && $guard++ < 800) {
                $dates[] = $day->format('Y-m-d');
                $day->modify('+1 day');
            }
        }

        return $dates;
    }

    /**
     * The dates this person has as an optional holiday (waiting or approved).
     *
     * @return array<string> dates as Y-m-d
     */
    public function getOptionalHolidayDates(User $user): array
    {
        $dates = [];
        foreach ($this->getMyLeave($user) as $leave) {
            if ($leave->getTitle() === 'Optional holiday' && $leave->getStatus() !== TeamEvent::STATUS_REJECTED) {
                $dates[] = $leave->getStartDate()->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * Leave this person already has on any of the given days. Rejected requests do not block a new one.
     *
     * An optional holiday inside a longer leave is not a clash - that day is simply not counted - so it can be
     * left out with $ignoreOptionalHolidays.
     *
     * @return array<TeamEvent>
     */
    public function findOverlappingLeave(User $user, \DateTimeInterface $from, \DateTimeInterface $to, bool $ignoreOptionalHolidays = false): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        $overlapping = [];
        foreach ($this->getMyLeave($user) as $leave) {
            if ($leave->getStatus() === TeamEvent::STATUS_REJECTED) {
                continue;
            }
            if ($ignoreOptionalHolidays && $leave->getTitle() === 'Optional holiday') {
                continue;
            }
            if ($leave->getStartDate()->format('Y-m-d') <= $last && $leave->getEndDate()->format('Y-m-d') >= $first) {
                $overlapping[] = $leave;
            }
        }

        return $overlapping;
    }

    /**
     * Optional holidays this person has already taken (or applied for) per calendar year. Rejected ones do not count.
     *
     * @return array<int, int> count keyed by year
     */
    public function getOptionalHolidaysUsed(User $user): array
    {
        $used = [];
        foreach ($this->getMyLeave($user) as $leave) {
            if ($leave->getTitle() !== 'Optional holiday' || $leave->getStatus() === TeamEvent::STATUS_REJECTED) {
                continue;
            }
            $year = (int) $leave->getStartDate()->format('Y');
            $used[$year] = ($used[$year] ?? 0) + 1;
        }

        return $used;
    }

    public function getLeaveBalance(User $user, int $year): float
    {
        return $this->getLeaveAllowance($user) - $this->getLeaveUsed($user, $year);
    }

    public function countsAgainstBalance(TeamEvent $leave): bool
    {
        return !\in_array($leave->getTitle(), self::LEAVE_TYPES_NOT_COUNTED, true);
    }

    /**
     * All leave this user applied for, newest first.
     *
     * @return array<TeamEvent>
     */
    public function getMyLeave(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.type = :type')
            ->andWhere('e.user = :user')
            ->setParameter('type', TeamEvent::TYPE_LEAVE)
            ->setParameter('user', $user)
            ->orderBy('e.startDate', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();
    }

    /**
     * Leave requests this user is the approver for: requests waiting for a decision first, then the latest decided ones.
     *
     * A request goes to the person's direct supervisor only - not further up the chain. Somebody without a
     * supervisor has nobody above them, so their requests go to the administrators instead.
     *
     * @return array<TeamEvent>
     */
    public function getTeamLeave(User $user, bool $pendingOnly = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e', 'u')
            ->from(TeamEvent::class, 'e')
            ->join('e.user', 'u')
            ->where('e.type = :type')
            ->andWhere('e.createdBy = e.user')
            ->andWhere('e.user != :me')
            ->setParameter('type', TeamEvent::TYPE_LEAVE)
            ->setParameter('me', $user)
            ->orderBy('e.startDate', 'DESC')
            ->setMaxResults(100);

        if ($this->isAdmin($user)) {
            $qb->andWhere('u.supervisor = :me OR u.supervisor IS NULL');
        } else {
            $qb->andWhere('u.supervisor = :me');
        }

        if ($pendingOnly) {
            $qb->andWhere('e.status = :pending')->setParameter('pending', TeamEvent::STATUS_PENDING);
        }

        $leave = $qb->getQuery()->getResult();

        usort($leave, static function (TeamEvent $a, TeamEvent $b): int {
            if ($a->isPending() !== $b->isPending()) {
                return $a->isPending() ? -1 : 1;
            }

            return $b->getStartDate() <=> $a->getStartDate();
        });

        return $leave;
    }

    /**
     * Leave requests waiting for this user's decision - what the notification bell shows.
     *
     * @return array<TeamEvent>
     */
    public function getLeaveNotifications(User $user): array
    {
        $pending = $this->getTeamLeave($user, true);
        // oldest request first
        usort($pending, static fn (TeamEvent $a, TeamEvent $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $pending;
    }

    /**
     * Only the person's direct supervisor approves or rejects. If they have no supervisor, an administrator does.
     * Nobody decides on their own leave.
     */
    public function canDecideLeave(User $user, TeamEvent $leave): bool
    {
        $applicant = $leave->getUser();
        if ($leave->getType() !== TeamEvent::TYPE_LEAVE || $applicant === null || $applicant->getId() === $user->getId()) {
            return false;
        }

        $supervisor = $applicant->getSupervisor();
        if ($supervisor !== null) {
            return $supervisor->getId() === $user->getId();
        }

        return $this->isAdmin($user);
    }

    /**
     * The person can withdraw their own leave while it is still waiting, or before it has started.
     */
    public function canCancelLeave(User $user, TeamEvent $leave): bool
    {
        if (!$leave->isLeaveRequest() || $leave->getUser()->getId() !== $user->getId()) {
            return false;
        }

        return $leave->isPending() || $leave->getStartDate()->format('Y-m-d') > (new \DateTime())->format('Y-m-d');
    }

    public function decideLeave(User $user, TeamEvent $leave, bool $approve, ?string $comment = null): void
    {
        $leave->setStatus($approve ? TeamEvent::STATUS_APPROVED : TeamEvent::STATUS_REJECTED);
        $leave->setDecidedBy($user);
        // the reason is optional and only kept for a rejection
        $leave->setDecisionComment($approve ? null : $comment);
        // the bell tells the person until they have looked at the Leave page
        $leave->setDecisionSeen(false);
        $this->save($leave);
    }

    /**
     * The person's own leave (comp-off included) that a manager approved or rejected and that they have not seen yet.
     *
     * @return array<TeamEvent>
     */
    public function getDecisionNotifications(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.type = :type')
            ->andWhere('e.user = :user')
            ->andWhere('e.decisionSeen = false')
            ->setParameter('type', TeamEvent::TYPE_LEAVE)
            ->setParameter('user', $user)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function markDecisionsSeen(User $user): void
    {
        $changed = false;
        foreach ($this->getDecisionNotifications($user) as $leave) {
            $leave->setDecisionSeen(true);
            $this->entityManager->persist($leave);
            $changed = true;
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }

    public function find(int $id): ?TeamEvent
    {
        return $this->entityManager->find(TeamEvent::class, $id);
    }

    public function save(TeamEvent $event): void
    {
        $event->fillMissingEndDate();
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    public function delete(TeamEvent $event): void
    {
        $this->entityManager->remove($event);
        $this->entityManager->flush();
    }

    /**
     * An event applies to a user when it is for everyone, for them personally, or for one of their teams.
     * People who add events also see the ones they created; administrators see all.
     */
    private function restrictToUser(QueryBuilder $qb, User $user, bool $personal = false): void
    {
        $qb->setParameter('leaveType', TeamEvent::TYPE_LEAVE);
        if ($personal) {
            // The dashboard shows a person their own leave, never anybody else's.
            $qb->andWhere('(e.type != :leaveType OR e.user = :me)');
        } else {
            // The Events page shows no leave at all: leave lives on the Leave page.
            // Previous rule, which listed the person's own leave here as well:
            // $qb->andWhere('(e.type != :leaveType OR e.user = :me)');
            $qb->andWhere('e.type != :leaveType');
        }

        // The Events page lets administrators see (and manage) every event.
        // A personal view - the dashboard box and the chart labels - never does: it shows what applies to the person.
        if (!$personal && $this->isAdmin($user)) {
            return;
        }

        // set only here: a query parameter that no condition uses makes the query fail
        $qb->setParameter('me', $user);

        $teamIds = [];
        foreach ($user->getTeams() as $team) {
            $teamIds[] = $team->getId();
        }

        $or = $qb->expr()->orX(
            // "Everyone" (was: '(e.user IS NULL AND e.team IS NULL)', before "All my teams" existed)
            '(e.user IS NULL AND e.team IS NULL AND e.allMyTeams = false)',
            'e.user = :me'
        );
        if ($teamIds !== []) {
            $or->add('e.team IN (:myTeams)');
            $qb->setParameter('myTeams', $teamIds);
        }
        // "All my teams": for everybody who shares a team with the person who added the event
        $mates = [(int) $user->getId()];
        foreach ($user->getTeams() as $team) {
            foreach ($team->getUsers() as $member) {
                $mates[] = (int) $member->getId();
            }
        }
        $or->add('(e.allMyTeams = true AND e.createdBy IN (:teamMates))');
        $qb->setParameter('teamMates', array_values(array_unique($mates)));
        // people also see the events they added for others (a team they are not in, somebody else), on the
        // Notifications page so they can change them, and on the dashboard so they see what they announced
        // (was: only on the Notifications page - "if (!$personal) { ... }")
        $or->add('e.createdBy = :me');

        $qb->andWhere($or);
    }
}
