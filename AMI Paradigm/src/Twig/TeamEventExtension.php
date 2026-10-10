<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\User;
use App\TeamEvent\TeamEventService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets templates label days with the events of the logged-in person,
 * for example the days in the dashboard's working-hours chart.
 */
final class TeamEventExtension extends AbstractExtension
{
    public function __construct(
        private readonly TeamEventService $events,
        private readonly Security $security,
        private readonly \App\Task\TaskService $tasks,
        private readonly \App\TeamEvent\AdditionalHoursService $additionalHours,
        private readonly \App\Timesheet\WeeklyCutoffService $weeklyCutoff,
        private readonly \App\WorkModel\WorkModelService $workModels,
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('team_events_by_day', [$this, 'eventsByDay']),
            new TwigFunction('week_events', [$this, 'weekEvents']),
            new TwigFunction('leave_notifications', [$this, 'leaveNotifications']),
            new TwigFunction('additional_hours_notifications', [$this, 'additionalHoursNotifications']),
            new TwigFunction('task_notifications', [$this, 'taskNotifications']),
            new TwigFunction('approved_leave_days', [$this, 'approvedLeaveDays']),
            new TwigFunction('leave_days', [$this, 'leaveDays']),
            new TwigFunction('leave_days_label', [$this, 'leaveDaysLabel']),
            new TwigFunction('timesheet_cutoff', [$this, 'timesheetCutoff']),
            new TwigFunction('project_user_map', [$this, 'projectUserMap']),
        ];
    }

    /**
     * The days a leave costs: weekdays only, without holidays and the person's optional holidays.
     */
    public function leaveDays(\App\Entity\TeamEvent $leave): int
    {
        try {
            return $this->events->getLeaveDays($leave);
        } catch (\Throwable) {
            return $leave->getWorkingDays();
        }
    }

    /**
     * Who works on each project (the members of its teams on Team Allocation), for the User box of the team
     * time entry forms: it only offers the people of the chosen project. Projects without a team are left out
     * (everybody can be picked for them).
     *
     * @return array<int, array<int>>
     */
    public function projectUserMap(): array
    {
        try {
            $projectIds = $this->workModels->getProjectsWithTeams();

            return array_filter($this->workModels->getProjectMembers($projectIds), static fn ($members) => $members !== null);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The weekly cut-off for the logged-in person, for the time entry forms (partials/cutoff-guard.html.twig).
     *
     * @return array<string, mixed>|null
     */
    public function timesheetCutoff(): ?array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return null;
        }

        return ['me' => (string) $user->getId()] + $this->weeklyCutoff->getOpenFrom($user);
    }

    /**
     * "2 days", or for a comp-off the part of the day its additional hours are worth ("Half day (0.5 day)").
     */
    public function leaveDaysLabel(\App\Entity\TeamEvent $leave): string
    {
        $credit = $leave->getCompCredit();
        if ($leave->isCompOff() && $credit !== null) {
            $days = (float) $credit->getCreditDays();

            return $credit->getCreditLabel() . ($days < 1 ? ' (' . $days . ' day)' : '');
        }
        $days = $this->leaveDays($leave);

        return $days . ($days === 1 ? ' day' : ' days');
    }

    /**
     * The days the logged-in person is on approved leave, so the time entry form can say so
     * instead of letting them fill it in (saving is refused by TimesheetApprovedLeaveValidator anyway).
     *
     * @return array<string, string> leave type keyed by date as Y-m-d
     */
    public function approvedLeaveDays(): array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return [];
        }

        try {
            $days = [];
            foreach ($this->events->getMyLeave($user) as $leave) {
                if ($leave->getStatus() !== \App\Entity\TeamEvent::STATUS_APPROVED) {
                    continue;
                }
                // a half day (or other part-day) comp-off leaves the rest of the day to work and log time
                if ($leave->isPartDay()) {
                    continue;
                }
                foreach ($this->events->getLeaveDates($leave) as $date) {
                    $days[$date] = (string) $leave->getTitle();
                }
            }

            return $days;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Tasks newly assigned to the logged-in person, for the notification bell in the top bar.
     *
     * @return array<\App\Entity\Task>
     */
    public function taskNotifications(): array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return [];
        }

        try {
            return $this->tasks->getUnseenTasks($user);
        } catch (\Throwable) {
            // the bell is on every page: it must never break one
            return [];
        }
    }

    /**
     * Leave requests waiting for the logged-in person's decision, for the notification bell in the top bar.
     *
     * @return array<\App\Entity\TeamEvent>
     */
    /**
     * For the bell: additional hours waiting for this person's decision, and the person's own additional hours and
     * leave (comp-off included) that were just approved or rejected.
     *
     * @return array{pending: array<\App\Entity\AdditionalHours>, hours: array<\App\Entity\AdditionalHours>, leave: array<\App\Entity\TeamEvent>}
     */
    public function additionalHoursNotifications(): array
    {
        $user = $this->security->getUser();
        $empty = ['pending' => [], 'hours' => [], 'leave' => []];
        if (!($user instanceof User)) {
            return $empty;
        }

        try {
            return [
                'pending' => $this->additionalHours->getTeamRequests($user, true),
                'hours' => $this->additionalHours->getDecisionNotifications($user),
                'leave' => $this->events->getDecisionNotifications($user),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    public function leaveNotifications(): array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return [];
        }

        try {
            return $this->events->getLeaveNotifications($user);
        } catch (\Throwable) {
            // the bell is on every page: it must never break one
            return [];
        }
    }

    /**
     * @return array<string, array<string>> event titles keyed by date as Y-m-d
     */
    /**
     * The events of the logged-in person on each day, for the top of the days in Bulk Entry (Week):
     * activities (team lunch: their hours are logged) and information (the head visits). Leave is left out.
     *
     * @param array<string, mixed> $days keyed by date as Y-m-d
     * @return array<string, array<array{title: string, kind: string, kindLabel: string, typeLabel: string, time: string, description: string, audience: string}>>
     */
    public function weekEvents(array $days): array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User) || $days === []) {
            return [];
        }
        $dates = array_keys($days);
        sort($dates);

        try {
            $byDay = [];
            foreach ($this->events->getEventsForUser($user, new \DateTime($dates[0]), new \DateTime(end($dates)), null, true) as $event) {
                if ($event->getType() === \App\Entity\TeamEvent::TYPE_LEAVE) {
                    continue;
                }
                $time = '';
                if ($event->getStartTime() !== null) {
                    $time = $event->getStartTime()->format('H:i') . ($event->getEndTime() !== null ? ' - ' . $event->getEndTime()->format('H:i') : '');
                }
                $item = [
                    'title' => (string) $event->getTitle(),
                    'kind' => $event->getKind(),
                    'kindLabel' => $event->getKindLabel(),
                    'typeLabel' => $event->getTypeLabel(),
                    'time' => $time,
                    'description' => (string) $event->getDescription(),
                    'audience' => $event->getAudienceLabel(),
                ];
                foreach ($dates as $date) {
                    if ($date >= $event->getStartDate()->format('Y-m-d') && $date <= $event->getEndDate()->format('Y-m-d')) {
                        $byDay[$date][] = $item;
                    }
                }
            }

            return $byDay;
        } catch (\Throwable) {
            // a marker must never break the page it decorates
            return [];
        }
    }

    public function eventsByDay(?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User) || $from === null || $to === null) {
            return [];
        }

        try {
            return $this->events->getTitlesByDay($user, $from, $to);
        } catch (\Throwable) {
            // a label must never break the chart it decorates
            return [];
        }
    }
}
