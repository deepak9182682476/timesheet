<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\TeamEvent;
use App\Entity\User;
use App\Entity\WfhRequest;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\TeamEventService;
use App\TeamEvent\WfhService;
use App\WorkModel\WorkModelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The colour of each day in Calendar Entry (see partials/calendar-day-colors.html.twig):
 * - leave: dark orange
 * - comp-off: light violet
 * - optional holiday (the person's approved one, or one of the holiday calendar): light pink
 * - Saturday, Sunday and fixed holidays of the holiday calendar: light blue
 * - work from home (approved): light green
 * Holidays and optional holidays come from the holiday calendar (the person's office; without an office, a day that
 * is a holiday at every office is a fixed holiday and any other festival day an optional one).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CalendarDayColorsController extends AbstractController
{
    public function __construct(
        private readonly HolidayCalendar $calendar,
        private readonly TeamEventService $events,
        private readonly WfhService $wfh,
        private readonly WorkModelService $models,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/calendar-day-colors', name: 'calendar_day_colors', methods: ['GET'])]
    public function dayColors(Request $request): JsonResponse
    {
        $viewer = $this->getUser();
        $person = $viewer;
        $userId = (int) $request->query->get('user', 0);
        if ($userId > 0 && $userId !== (int) $viewer->getId()) {
            $allowed = $this->events->isAdmin($viewer);
            if (!$allowed) {
                foreach ($this->models->getTeamPeople($viewer) as $member) {
                    if ((int) $member->getId() === $userId) {
                        $allowed = true;
                        break;
                    }
                }
            }
            $other = $allowed ? $this->entityManager->find(User::class, $userId) : null;
            if ($other !== null) {
                $person = $other;
            }
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $request->query->get('from', ''), 0, 10));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $request->query->get('to', ''), 0, 10));
        if ($from === false || $to === false || $to < $from || $from->diff($to)->days > 62) {
            return new JsonResponse((object) []);
        }
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        $days = [];
        // the holiday calendar
        $office = HolidayCalendar::getUserLocation($person);
        foreach ($this->calendar->getDaysByLocation() as $date => $festival) {
            if ($date < $first || $date > $last) {
                continue;
            }
            if ($office !== null) {
                $code = $festival['codes'][$office] ?? null;
                $kind = $code === HolidayCalendar::HOLIDAY ? 'holiday' : ($code === HolidayCalendar::OPTIONAL ? 'optional' : null);
            } else {
                $everywhere = !\in_array(false, array_map(static fn ($c) => $c === HolidayCalendar::HOLIDAY, $festival['codes']), true);
                $any = \count(array_filter($festival['codes'], static fn ($c) => $c === HolidayCalendar::HOLIDAY || $c === HolidayCalendar::OPTIONAL)) > 0;
                $kind = $everywhere ? 'holiday' : ($any ? 'optional' : null);
            }
            if ($kind !== null) {
                $days[$date] = ['kind' => $kind, 'label' => $festival['name'] . ($kind === 'optional' ? ' (optional)' : '')];
            }
        }
        // Saturdays and Sundays
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') >= 6 && !isset($days[$day->format('Y-m-d')])) {
                $days[$day->format('Y-m-d')] = ['kind' => 'weekend', 'label' => ''];
            }
        }
        // approved work from home
        foreach ($this->wfh->getMine($person) as $request) {
            if (!$request->isApproved()) {
                continue;
            }
            $this->mark($days, $request->getStartDate(), $request->getEndDate(), $first, $last, 'wfh', 'Work from home', ['weekend', 'holiday']);
        }
        // approved leave: comp-off, optional holiday, other leave (stronger than everything above)
        foreach ($this->events->getMyLeave($person) as $leave) {
            if ($leave->getStatus() !== TeamEvent::STATUS_APPROVED) {
                continue;
            }
            if ($leave->isCompOff()) {
                $this->mark($days, $leave->getStartDate(), $leave->getEndDate(), $first, $last, 'compoff', 'Comp-off' . ($leave->isPartDay() ? ' (part of the day)' : ''), []);
            } elseif ($leave->getTitle() === 'Optional holiday') {
                $this->mark($days, $leave->getStartDate(), $leave->getEndDate(), $first, $last, 'optional', 'Optional holiday (yours)', []);
            } else {
                $this->mark($days, $leave->getStartDate(), $leave->getEndDate(), $first, $last, 'leave', (string) $leave->getTitle(), ['weekend', 'holiday']);
            }
        }

        return new JsonResponse((object) $days);
    }

    /**
     * @param array<string, array{kind: string, label: string}> $days
     * @param array<string> $keep kinds that stay as they are (a leave over a weekend does not colour the weekend)
     */
    private function mark(array &$days, ?\DateTimeInterface $start, ?\DateTimeInterface $end, string $first, string $last, string $kind, string $label, array $keep): void
    {
        if ($start === null || $end === null) {
            return;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', max($first, $start->format('Y-m-d')));
        $stop = min($last, $end->format('Y-m-d'));
        $guard = 0;
        while ($day->format('Y-m-d') <= $stop && $guard++ < 400) {
            $date = $day->format('Y-m-d');
            if (!isset($days[$date]) || !\in_array($days[$date]['kind'], $keep, true)) {
                $days[$date] = ['kind' => $kind, 'label' => $label];
            }
            $day = $day->modify('+1 day');
        }
    }
}
