<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\TeamEvent;

use App\Entity\Activity;
use App\Entity\ActivityTask;
use App\Entity\Phase;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TeamEvent;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts approved leave into the person's timesheet, so nobody has to enter it a second time.
 *
 * Every working day of an approved leave becomes one time entry of HOURS_PER_DAY hours on
 * Non-Project Activities > Leave & Time Off > Leave. The entries carry the number of the leave request:
 * when the leave is cancelled or rejected afterwards, they are removed again.
 * People can still enter leave by hand; a day that already has a leave entry is left alone.
 */
final class LeaveTimesheetSync
{
    public const HOURS_PER_DAY = 10;
    public const LEAVE_PHASE = 'Leave & Time Off';
    public const LEAVE_ACTIVITY = 'Leave';
    /** custom field on the time entry that points back to the leave request */
    public const META_FIELD = 'leave_request';
    /** leave type => standard task; everything else is booked as the default */
    private const TASKS = ['Sick leave' => 'Sick Leave', 'Optional holiday' => 'Public Holiday'];
    private const DEFAULT_TASK = 'Vacation / PTO';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEventService $events,
    ) {
    }

    /**
     * Creates the entries for every approved leave of this person that has none yet.
     */
    public function syncUser(User $user): void
    {
        $pending = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamEvent::class, 'e')
            ->where('e.user = :user')
            ->andWhere('e.type = :type')
            ->andWhere('e.status = :status')
            ->andWhere('e.timesheetSynced = :synced')
            ->setParameter('user', $user)
            ->setParameter('type', TeamEvent::TYPE_LEAVE)
            ->setParameter('status', TeamEvent::STATUS_APPROVED)
            ->setParameter('synced', false)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($pending as $leave) {
            $this->sync($leave);
        }
    }

    /**
     * Creates the entries of one approved leave.
     */
    public function sync(TeamEvent $leave): void
    {
        $user = $leave->getUser();
        if ($user === null || $leave->getType() !== TeamEvent::TYPE_LEAVE || $leave->getStatus() !== TeamEvent::STATUS_APPROVED || $leave->isTimesheetSynced()) {
            return;
        }

        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['name' => Phase::NON_PROJECT_NAME]);
        $activity = $project !== null ? $this->entityManager->getRepository(Activity::class)->findOneBy(['name' => self::LEAVE_ACTIVITY, 'project' => $project]) : null;
        if ($project === null || $activity === null) {
            // the leave project or activity was removed or renamed: nothing to book on
            return;
        }

        $taskName = self::TASKS[$leave->getTitle()] ?? self::DEFAULT_TASK;
        $task = $this->entityManager->getRepository(ActivityTask::class)->findOneBy(['name' => $taskName, 'activity' => $activity]);

        $timezone = new \DateTimeZone($user->getTimezone());
        $seconds = self::HOURS_PER_DAY * 3600;
        $description = trim($leave->getTitle() . ($leave->getDescription() ? ' - ' . $leave->getDescription() : ''));

        foreach ($this->events->getLeaveDates($leave) as $date) {
            if ($this->hasLeaveEntry($user, $date, $timezone)) {
                continue;
            }

            $begin = new \DateTime($date . ' 09:00:00', $timezone);
            $timesheet = new Timesheet();
            $timesheet->setUser($user);
            $timesheet->setBegin($begin);
            $timesheet->setEnd((clone $begin)->modify('+' . $seconds . ' seconds'));
            $timesheet->setDuration($seconds);
            $timesheet->setProject($project);
            $timesheet->setActivity($activity);
            $timesheet->setDescription($description);
            $timesheet->setBillable(false);

            $this->addMeta($timesheet, Phase::TIMESHEET_META_FIELD, self::LEAVE_PHASE);
            $this->addMeta($timesheet, self::META_FIELD, (string) $leave->getId());
            if ($task !== null) {
                $this->addMeta($timesheet, Task::TIMESHEET_META_FIELD, (string) $task->getName());
            }

            $this->entityManager->persist($timesheet);
        }

        $leave->setTimesheetSynced(true);
        $this->entityManager->persist($leave);
        $this->entityManager->flush();
    }

    /**
     * Removes the entries that were created for this leave (it was cancelled or rejected).
     */
    public function remove(TeamEvent $leave): void
    {
        if ($leave->getId() === null) {
            return;
        }

        $entries = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->join(TimesheetMeta::class, 'm', 'WITH', 'm.timesheet = t')
            ->where('m.name = :name')
            ->andWhere('m.value = :value')
            ->setParameter('name', self::META_FIELD)
            ->setParameter('value', (string) $leave->getId())
            ->getQuery()
            ->getResult();

        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }
        $leave->setTimesheetSynced(false);
        $this->entityManager->flush();
    }

    private function addMeta(Timesheet $timesheet, string $name, string $value): void
    {
        $meta = new TimesheetMeta();
        $meta->setName($name);
        $meta->setValue($value);
        $timesheet->setMetaField($meta);
    }

    /**
     * True when the person already has a leave entry on that day, for example one they typed in themselves.
     */
    private function hasLeaveEntry(User $user, string $date, \DateTimeZone $timezone): bool
    {
        $from = new \DateTime($date . ' 00:00:00', $timezone);
        $to = new \DateTime($date . ' 23:59:59', $timezone);

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Timesheet::class, 't')
            ->join(TimesheetMeta::class, 'm', 'WITH', 'm.timesheet = t')
            ->where('t.user = :user')
            ->andWhere('t.begin BETWEEN :from AND :to')
            ->andWhere('m.name = :name')
            ->andWhere('m.value = :phase')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('name', Phase::TIMESHEET_META_FIELD)
            ->setParameter('phase', self::LEAVE_PHASE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
