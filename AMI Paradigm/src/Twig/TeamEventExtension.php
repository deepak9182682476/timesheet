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
        private readonly \App\Task\TaskService $tasks
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('team_events_by_day', [$this, 'eventsByDay']),
            new TwigFunction('leave_notifications', [$this, 'leaveNotifications']),
            new TwigFunction('task_notifications', [$this, 'taskNotifications']),
            new TwigFunction('approved_leave_days', [$this, 'approvedLeaveDays']),
            new TwigFunction('leave_days', [$this, 'leaveDays']),
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
