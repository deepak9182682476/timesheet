<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\TeamEvent;

use App\Entity\Activity;
use App\Entity\Phase;
use App\Entity\Project;
use App\Entity\TeamEvent;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Logs the hours of an "activity" event (team lunch, training ...) in the timesheet of everybody it applies to,
 * so nobody has to enter it: Non-Project Activities > Team Events > Team Event, with the event title as description.
 * One entry per person and day, from the event's start to its end time. Somebody on approved leave that day gets none.
 *
 * The entries carry the number of the event. When the event is changed they are made again (so new times, days or
 * people are followed); when it is deleted or turned into "information only" they are removed.
 * A person can still delete their own entry, for example when they did not take part.
 */
final class TeamEventTimesheetSync
{
    public const PHASE = 'Team Events';
    public const ACTIVITY = 'Team Event';
    /** custom field on the time entry that points back to the event */
    public const META_FIELD = 'team_event';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Makes the entries of the event match it: removes what was logged for it before, then logs it again.
     *
     * @return int the number of entries made
     */
    public function sync(TeamEvent $event): int
    {
        $this->remove($event);
        if (!$event->isActivity() || $event->getType() === TeamEvent::TYPE_LEAVE || $event->getStatus() !== TeamEvent::STATUS_APPROVED
            || $event->getStartTime() === null || $event->getEndTime() === null || $event->getStartDate() === null) {
            return 0;
        }

        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['name' => Phase::NON_PROJECT_NAME]);
        $activity = $project !== null ? $this->entityManager->getRepository(Activity::class)->findOneBy(['name' => self::ACTIVITY, 'project' => $project]) : null;
        if ($project === null || $activity === null) {
            return 0;
        }

        $from = $event->getStartTime()->format('H:i:s');
        $seconds = $this->seconds($event);
        if ($seconds <= 0) {
            return 0;
        }
        $description = trim($event->getTitle() . ($event->getDescription() ? ' - ' . $event->getDescription() : ''));

        $made = 0;
        foreach ($this->getPeople($event) as $person) {
            $timezone = new \DateTimeZone($person->getTimezone());
            foreach ($this->getDates($event) as $date) {
                if ($this->onLeave($person, $date, $timezone)) {
                    continue;
                }
                $begin = new \DateTime($date . ' ' . $from, $timezone);
                $timesheet = new Timesheet();
                $timesheet->setUser($person);
                $timesheet->setBegin($begin);
                $timesheet->setEnd((clone $begin)->modify('+' . $seconds . ' seconds'));
                $timesheet->setDuration($seconds);
                $timesheet->setProject($project);
                $timesheet->setActivity($activity);
                $timesheet->setDescription($description);
                $timesheet->setBillable(false);
                $this->addMeta($timesheet, Phase::TIMESHEET_META_FIELD, self::PHASE);
                $this->addMeta($timesheet, self::META_FIELD, (string) $event->getId());
                $this->entityManager->persist($timesheet);
                $made++;
            }
        }
        $this->entityManager->flush();

        return $made;
    }

    /**
     * Removes the entries that were logged for this event.
     */
    public function remove(TeamEvent $event): void
    {
        if ($event->getId() === null) {
            return;
        }

        $entries = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->join(TimesheetMeta::class, 'm', 'WITH', 'm.timesheet = t')
            ->where('m.name = :name')
            ->andWhere('m.value = :value')
            ->setParameter('name', self::META_FIELD)
            ->setParameter('value', (string) $event->getId())
            ->getQuery()
            ->getResult();

        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
    }

    /**
     * Everybody the event applies to: the person it is for, the members of its team, the members of all teams of
     * the person who added it ("All my teams"), or every active person ("Everyone"). Only active people.
     *
     * @return array<User>
     */
    public function getPeople(TeamEvent $event): array
    {
        $people = [];
        if ($event->getUser() !== null) {
            $people[] = $event->getUser();
        } elseif ($event->getTeam() !== null) {
            $people = $event->getTeam()->getUsers();
        } elseif ($event->isAllMyTeams()) {
            foreach ($event->getCreatedBy()?->getTeams() ?? [] as $team) {
                foreach ($team->getUsers() as $member) {
                    $people[] = $member;
                }
            }
        } else {
            $people = $this->entityManager->getRepository(User::class)->findBy(['enabled' => true]);
        }

        $unique = [];
        foreach ($people as $person) {
            if ($person instanceof User && $person->isEnabled()) {
                $unique[(int) $person->getId()] = $person;
            }
        }

        return array_values($unique);
    }

    /**
     * The hours of one day of the event, in seconds.
     */
    public function seconds(TeamEvent $event): int
    {
        if ($event->getStartTime() === null || $event->getEndTime() === null) {
            return 0;
        }
        $start = (int) $event->getStartTime()->format('H') * 3600 + (int) $event->getStartTime()->format('i') * 60;
        $end = (int) $event->getEndTime()->format('H') * 3600 + (int) $event->getEndTime()->format('i') * 60;

        return max(0, $end - $start);
    }

    /**
     * @return array<string> the days of the event as Y-m-d
     */
    private function getDates(TeamEvent $event): array
    {
        $dates = [];
        $day = \DateTime::createFromInterface($event->getStartDate());
        $last = ($event->getEndDate() ?? $event->getStartDate())->format('Y-m-d');
        $guard = 0;
        while ($day->format('Y-m-d') <= $last && $guard++ < 62) {
            $dates[] = $day->format('Y-m-d');
            $day->modify('+1 day');
        }

        return $dates;
    }

    private function addMeta(Timesheet $timesheet, string $name, string $value): void
    {
        $meta = new TimesheetMeta();
        $meta->setName($name);
        $meta->setValue($value);
        $timesheet->setMetaField($meta);
    }

    /**
     * True when the person has a leave entry that day: they are not there, so nothing is logged for them.
     */
    private function onLeave(User $user, string $date, \DateTimeZone $timezone): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Timesheet::class, 't')
            ->join(TimesheetMeta::class, 'm', 'WITH', 'm.timesheet = t')
            ->where('t.user = :user')
            ->andWhere('t.begin BETWEEN :from AND :to')
            ->andWhere('m.name = :name')
            ->andWhere('m.value = :phase')
            ->setParameter('user', $user)
            ->setParameter('from', new \DateTime($date . ' 00:00:00', $timezone))
            ->setParameter('to', new \DateTime($date . ' 23:59:59', $timezone))
            ->setParameter('name', Phase::TIMESHEET_META_FIELD)
            ->setParameter('phase', LeaveTimesheetSync::LEAVE_PHASE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
