<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\TeamEvent;

use App\Entity\AdditionalHours;
use App\Entity\TeamEvent;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Additional hours and the comp-off credits they give (see AdditionalHours).
 * Approver: the person's direct supervisor, or an administrator when they have none - the same as for leave.
 */
final class AdditionalHoursService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEventService $events,
    ) {
    }

    public function find(int $id): ?AdditionalHours
    {
        return $this->entityManager->find(AdditionalHours::class, $id);
    }

    public function save(AdditionalHours $hours): void
    {
        $this->entityManager->persist($hours);
        $this->entityManager->flush();
    }

    public function delete(AdditionalHours $hours): void
    {
        $this->entityManager->remove($hours);
        $this->entityManager->flush();
    }

    /**
     * @return array<AdditionalHours> newest first
     */
    public function getMine(User $user): array
    {
        return $this->entityManager->getRepository(AdditionalHours::class)->findBy(['user' => $user], ['workDate' => 'DESC'], 300);
    }

    /**
     * The requests of the people this person approves for: waiting ones first, then the latest decided ones.
     *
     * @return array<AdditionalHours>
     */
    public function getTeamRequests(User $user, bool $pendingOnly = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('h', 'u')
            ->from(AdditionalHours::class, 'h')
            ->join('h.user', 'u')
            ->where('h.user != :me')
            ->setParameter('me', $user)
            ->orderBy('h.workDate', 'DESC')
            ->setMaxResults(100);
        if ($this->events->isAdmin($user)) {
            $qb->andWhere('u.supervisor = :me OR u.supervisor IS NULL');
        } else {
            $qb->andWhere('u.supervisor = :me');
        }
        if ($pendingOnly) {
            $qb->andWhere('h.status = :pending')->setParameter('pending', AdditionalHours::STATUS_PENDING);
        }
        $requests = $qb->getQuery()->getResult();
        usort($requests, static fn (AdditionalHours $a, AdditionalHours $b) => [$b->isPending(), $b->getWorkDate()] <=> [$a->isPending(), $a->getWorkDate()]);

        return $requests;
    }

    public function canDecide(User $user, AdditionalHours $hours): bool
    {
        $person = $hours->getUser();
        if ($person === null || $person->getId() === $user->getId()) {
            return false;
        }
        if ($person->getSupervisor() !== null) {
            return $person->getSupervisor()->getId() === $user->getId();
        }

        return $this->events->isAdmin($user);
    }

    /** the person can take back a request while it waits for the decision */
    public function canCancel(User $user, AdditionalHours $hours): bool
    {
        return $hours->isPending() && $hours->getUser()?->getId() === $user->getId();
    }

    public function decide(User $user, AdditionalHours $hours, bool $approve, ?string $comment): void
    {
        $hours->setStatus($approve ? AdditionalHours::STATUS_APPROVED : AdditionalHours::STATUS_REJECTED);
        $hours->setDecidedBy($user);
        $hours->setDecisionComment($approve ? null : $comment);
        $hours->setDecisionSeen(false);
        $this->save($hours);
    }

    /**
     * Approved additional hours that are not used yet (no comp-off asked for them that was not rejected)
     * and not expired: what the person can take as comp-off.
     *
     * @param TeamEvent|null $except a comp-off whose own credit counts as free (when it is checked again)
     * @return array<AdditionalHours> oldest first, so the one that expires first comes first
     */
    public function getAvailableCredits(User $user, ?TeamEvent $except = null): array
    {
        $used = [];
        foreach ($this->events->getMyLeave($user) as $leave) {
            if ($leave->isCompOff() && $leave->getStatus() !== TeamEvent::STATUS_REJECTED && $leave->getCompCredit() !== null && $leave !== $except) {
                $used[(int) $leave->getCompCredit()->getId()] = true;
            }
        }

        $available = [];
        foreach ($this->entityManager->getRepository(AdditionalHours::class)->findBy(['user' => $user, 'status' => AdditionalHours::STATUS_APPROVED], ['workDate' => 'ASC']) as $hours) {
            if (!isset($used[(int) $hours->getId()]) && !$hours->isExpired() && $hours->getCreditDays() > 0) {
                $available[] = $hours;
            }
        }

        return $available;
    }

    /**
     * The comp-off that uses these hours (not rejected), if any.
     */
    public function getUsedBy(AdditionalHours $hours): ?TeamEvent
    {
        return $this->entityManager->getRepository(TeamEvent::class)->createQueryBuilder('e')
            ->where('e.compCredit = :hours')
            ->andWhere('e.status != :rejected')
            ->setParameter('hours', $hours)
            ->setParameter('rejected', TeamEvent::STATUS_REJECTED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Does the person have any time entry on that day?
     */
    public function hasTimesheet(User $user, \DateTimeInterface $day): bool
    {
        $timezone = new \DateTimeZone($user->getTimezone());
        $from = new \DateTime($day->format('Y-m-d') . ' 00:00:00', $timezone);
        $to = new \DateTime($day->format('Y-m-d') . ' 23:59:59', $timezone);

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Timesheet::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.begin BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The person's own requests that were decided and not looked at yet (the bell).
     *
     * @return array<AdditionalHours>
     */
    public function getDecisionNotifications(User $user): array
    {
        return $this->entityManager->getRepository(AdditionalHours::class)->findBy(['user' => $user, 'decisionSeen' => false], ['workDate' => 'ASC']);
    }

    public function markDecisionsSeen(User $user): void
    {
        $changed = false;
        foreach ($this->getDecisionNotifications($user) as $hours) {
            $hours->setDecisionSeen(true);
            $this->entityManager->persist($hours);
            $changed = true;
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }
}
