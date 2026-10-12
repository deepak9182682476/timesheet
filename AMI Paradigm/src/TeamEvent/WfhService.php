<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\TeamEvent;

use App\Entity\TeamEvent;
use App\Entity\User;
use App\Entity\WfhRequest;
use App\Holiday\HolidayCalendar;
use Doctrine\ORM\EntityManagerInterface;

/**
 * WFH requests. Approver: the person's immediate supervisor, or an administrator when they have none - the same as
 * for leave and Extra Hours Claim.
 */
final class WfhService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEventService $events,
        private readonly HolidayCalendar $calendar,
    ) {
    }

    /** the leave type that is a WFH request (Apply Leave > Leave & Comp-off) */
    public const LEAVE_KIND = 'Work from home';
    /** the longest request, in days */
    public const MAX_DAYS = 31;

    /**
     * The days a person cannot work from home, with the reason: Saturdays and Sundays, the holidays of their office
     * (without an office: the holidays of every office), and their approved leave - optional holidays included.
     * A comp-off for part of a day leaves the rest of the day to work, so it does not block the day.
     *
     * @return array<string, string> reason keyed by date (Y-m-d)
     */
    public function getBlockedDays(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $office = HolidayCalendar::getUserLocation($user);
        $holidays = $office !== null ? $this->calendar->getHolidays($office) : $this->calendar->getHolidaysEverywhere();

        $blocked = [];
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $first);
        $guard = 0;
        while ($day->format('Y-m-d') <= $last && $guard++ < 400) {
            $date = $day->format('Y-m-d');
            if ((int) $day->format('N') >= 6) {
                $blocked[$date] = 'a ' . $day->format('l');
            } elseif (isset($holidays[$date])) {
                $blocked[$date] = 'a holiday (' . $holidays[$date] . ')';
            }
            $day = $day->modify('+1 day');
        }

        foreach ($this->events->getMyLeave($user) as $leave) {
            if ($leave->getStatus() !== TeamEvent::STATUS_APPROVED || $leave->isPartDay()) {
                continue;
            }
            $start = max($first, $leave->getStartDate()->format('Y-m-d'));
            $end = min($last, $leave->getEndDate()->format('Y-m-d'));
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            $guard = 0;
            while ($start <= $end && $day->format('Y-m-d') <= $end && $guard++ < 400) {
                $blocked[$day->format('Y-m-d')] ??= 'approved ' . ($leave->getTitle() === 'Optional holiday' ? 'optional holiday' : mb_strtolower((string) $leave->getTitle()));
                $day = $day->modify('+1 day');
            }
        }

        return $blocked;
    }

    /**
     * What is wrong with a WFH request, if anything: [form field, message]. 'taken' as the field means the days
     * were asked for already (the form shows that as a popup).
     *
     * @return array{0: string, 1: string}|null
     */
    public function check(WfhRequest $request): ?array
    {
        $user = $request->getUser();
        $from = $request->getStartDate();
        $to = $request->getEndDate();
        if ($user === null || $from === null || $to === null) {
            return null;
        }
        $oldest = (new \DateTimeImmutable('today'))->modify('-' . WfhRequest::DAYS_BACK . ' days')->format('Y-m-d');
        if ($from->format('Y-m-d') < $oldest) {
            return ['startDate', 'Work from home can be asked for up to ' . WfhRequest::DAYS_BACK . ' days back.'];
        }
        if ($to->format('Y-m-d') < $from->format('Y-m-d')) {
            return ['endDate', 'The last day cannot be before the first day.'];
        }
        if ($request->getDays() > self::MAX_DAYS) {
            return ['endDate', 'A request can cover at most ' . self::MAX_DAYS . ' days.'];
        }

        $blocked = $this->getBlockedDays($user, $from, $to);
        foreach (['startDate' => $from, 'endDate' => $to] as $field => $date) {
            $reason = $blocked[$date->format('Y-m-d')] ?? null;
            if ($reason !== null) {
                return [$field, 'You cannot work from home on ' . $date->format('d-M-y') . ': it is ' . (str_starts_with($reason, 'approved') ? 'your ' . $reason : $reason) . '.'];
            }
        }
        if ($this->countDays($request, $blocked) === 0) {
            return ['startDate', 'There is no working day in these dates to work from home on.'];
        }

        $overlap = $this->findOverlapping($user, $from, $to, $request);
        if ($overlap !== []) {
            $earlier = $overlap[0];
            $days = $earlier->getStartDate()->format('d-M-y') . ($earlier->isMultiDay() ? ' to ' . $earlier->getEndDate()->format('d-M-y') : '');

            return ['taken', 'You already asked to work from home on ' . $days . ' (' . ($earlier->isApproved() ? 'Approved' : 'Waiting for approval') . '). The same day cannot be asked for twice.'];
        }

        return null;
    }

    /**
     * The working days of a request: weekends, holidays and approved leave inside the dates are not counted.
     *
     * @param array<string, string>|null $blocked
     */
    public function countDays(WfhRequest $request, ?array $blocked = null): int
    {
        if ($request->getUser() === null || $request->getStartDate() === null || $request->getEndDate() === null) {
            return 0;
        }
        $blocked ??= $this->getBlockedDays($request->getUser(), $request->getStartDate(), $request->getEndDate());

        return max(0, $request->getDays() - \count($blocked));
    }

    public function find(int $id): ?WfhRequest
    {
        return $this->entityManager->find(WfhRequest::class, $id);
    }

    public function save(WfhRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    public function delete(WfhRequest $request): void
    {
        $this->entityManager->remove($request);
        $this->entityManager->flush();
    }

    /**
     * @return array<WfhRequest> newest first
     */
    public function getMine(User $user): array
    {
        return $this->entityManager->getRepository(WfhRequest::class)->findBy(['user' => $user], ['startDate' => 'DESC'], 300);
    }

    /**
     * The requests of the people this person approves for: waiting ones first, then the latest decided ones.
     *
     * @return array<WfhRequest>
     */
    public function getTeamRequests(User $user, bool $pendingOnly = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('w', 'u')
            ->from(WfhRequest::class, 'w')
            ->join('w.user', 'u')
            ->where('w.user != :me')
            ->setParameter('me', $user)
            ->orderBy('w.startDate', 'DESC')
            ->setMaxResults(100);
        if ($this->events->isAdmin($user)) {
            $qb->andWhere('u.supervisor = :me OR u.supervisor IS NULL');
        } else {
            $qb->andWhere('u.supervisor = :me');
        }
        if ($pendingOnly) {
            $qb->andWhere('w.status = :pending')->setParameter('pending', WfhRequest::STATUS_PENDING);
        }
        $requests = $qb->getQuery()->getResult();
        usort($requests, static fn (WfhRequest $a, WfhRequest $b) => [$b->isPending(), $b->getStartDate()] <=> [$a->isPending(), $a->getStartDate()]);

        return $requests;
    }

    public function canDecide(User $user, WfhRequest $request): bool
    {
        $person = $request->getUser();
        if ($person === null || $person->getId() === $user->getId()) {
            return false;
        }
        if ($person->getSupervisor() !== null) {
            return $person->getSupervisor()->getId() === $user->getId();
        }

        return $this->events->isAdmin($user);
    }

    /** the person can take back a request while it waits for the decision */
    public function canCancel(User $user, WfhRequest $request): bool
    {
        return $request->isPending() && $request->getUser()?->getId() === $user->getId();
    }

    public function decide(User $user, WfhRequest $request, bool $approve, ?string $comment): void
    {
        $request->setStatus($approve ? WfhRequest::STATUS_APPROVED : WfhRequest::STATUS_REJECTED);
        $request->setDecidedBy($user);
        $request->setDecisionComment($approve ? null : $comment);
        $request->setDecisionSeen(false);
        $this->save($request);
    }

    /**
     * The person's requests (not rejected) that share a day with these dates.
     *
     * @return array<WfhRequest>
     */
    public function findOverlapping(User $user, \DateTimeInterface $from, \DateTimeInterface $to, ?WfhRequest $except = null): array
    {
        $found = [];
        foreach ($this->getMine($user) as $request) {
            if ($request === $except || $request->getStatus() === WfhRequest::STATUS_REJECTED) {
                continue;
            }
            if ($request->getStartDate()->format('Y-m-d') <= $to->format('Y-m-d') && $request->getEndDate()->format('Y-m-d') >= $from->format('Y-m-d')) {
                $found[] = $request;
            }
        }

        return $found;
    }

    /**
     * The person's own requests that were decided and not looked at yet (the bell).
     *
     * @return array<WfhRequest>
     */
    public function getDecisionNotifications(User $user): array
    {
        return $this->entityManager->getRepository(WfhRequest::class)->findBy(['user' => $user, 'decisionSeen' => false], ['startDate' => 'ASC']);
    }

    public function markDecisionsSeen(User $user): void
    {
        $changed = false;
        foreach ($this->getDecisionNotifications($user) as $request) {
            $request->setDecisionSeen(true);
            $this->entityManager->persist($request);
            $changed = true;
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }
}
