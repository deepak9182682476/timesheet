<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\TeamEvent;
use App\Form\LeaveApplyForm;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\LeaveTimesheetSync;
use App\TeamEvent\TeamEventService;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Apply leave: a person applies for their own leave and a manager above them approves or rejects it.
 * Also shows the company holiday calendar.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LeaveController extends AbstractController
{
    /** The holiday calendar picture, kept next to the code so it is available without rebuilding the image */
    private const HOLIDAY_CALENDAR = '/src/Holiday/amip-holiday-calendar-2026.png';

    public function __construct(
        private readonly TeamEventService $events,
        private readonly HolidayCalendar $calendar,
        private readonly LeaveTimesheetSync $timesheetSync
    )
    {
    }

    #[Route(path: '/leave/', name: 'leave', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $mine = $this->events->getMyLeave($user);
        $team = $this->events->getTeamLeave($user);

        $cancellable = [];
        foreach ($mine as $leave) {
            $cancellable[$leave->getId()] = $this->events->canCancelLeave($user, $leave);
        }
        $decidable = [];
        foreach ($team as $leave) {
            $decidable[$leave->getId()] = $this->events->canDecideLeave($user, $leave);
        }

        $year = (int) date('Y');

        return $this->render('leave/index.html.twig', [
            'page_setup' => new PageSetup('Leave'),
            'year' => $year,
            'allowance' => $this->events->getLeaveAllowance($user),
            'used' => $this->events->getLeaveUsed($user, $year),
            'balance' => $this->events->getLeaveBalance($user, $year),
            'not_counted' => TeamEventService::LEAVE_TYPES_NOT_COUNTED,
            'my_leave' => $mine,
            'team_leave' => $team,
            'cancellable' => $cancellable,
            'decidable' => $decidable,
        ]);
    }

    #[Route(path: '/leave/apply', name: 'leave_apply', methods: ['GET', 'POST'])]
    public function apply(Request $request): Response
    {
        $user = $this->getUser();
        $leave = new TeamEvent();
        $leave->setType(TeamEvent::TYPE_LEAVE);

        $form = $this->createForm(LeaveApplyForm::class, $leave, [
            'action' => $this->generateUrl('leave_apply'),
            'method' => 'POST',
            // the person's office, when an administrator set it
            'office' => HolidayCalendar::getUserLocation($user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // always store the last day explicitly: empty, or the same as the first day, both mean a single day
            $lastDay = $form->get('endDate')->getData();
            $leave->setEndDate($lastDay !== null ? clone $lastDay : clone $leave->getStartDate());
            if ($leave->getEndDate()->format('Y-m-d') < $leave->getStartDate()->format('Y-m-d')) {
                $form->get('endDate')->addError(new FormError('The last day cannot be before the first day.'));
            }

            if ($form->isValid()) {
                $this->validateLeave($form, $leave);
            }

            if ($form->isValid()) {
                try {
                    $this->events->applyForLeave($user, $leave);
                    // leave that needs no approval (an optional holiday) goes into the timesheet right away
                    $this->timesheetSync->sync($leave);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('leave');
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        $year = (int) date('Y');

        // the dates this person already has leave on, so the form can warn as soon as a date is picked
        $taken = [];
        foreach ($this->events->getMyLeave($user) as $other) {
            if ($other->getStatus() === TeamEvent::STATUS_REJECTED) {
                continue;
            }
            $taken[] = [
                'optional' => $other->getTitle() === 'Optional holiday',
                'from' => $other->getStartDate()->format('Y-m-d'),
                'to' => $other->getEndDate()->format('Y-m-d'),
                'label' => $other->getStartDate()->format('d-M-y')
                    . ($other->isMultiDay() ? ' to ' . $other->getEndDate()->format('d-M-y') : '')
                    . ' (' . $other->getTitle() . ', ' . ($other->isPending() ? 'waiting for approval' : 'approved') . ')',
            ];
        }

        return $this->render('leave/apply.html.twig', [
            'page_setup' => new PageSetup('Leave'),
            'form' => $form->createView(),
            'year' => $year,
            'balance' => $this->events->getLeaveBalance($user, $year),
            'overdraw' => TeamEventService::LEAVE_OVERDRAW_DAYS,
            'not_counted' => TeamEventService::LEAVE_TYPES_NOT_COUNTED,
            'optional_holidays' => $this->calendar->getOptionalHolidaysByLocation(),
            'taken' => $taken,
            'optional_used' => $this->events->getOptionalHolidaysUsed($user),
            'optional_limit' => TeamEventService::OPTIONAL_HOLIDAYS_PER_YEAR,
            'festivals' => $this->calendar->getDaysByLocation(),
        ]);
    }

    #[Route(path: '/leave/{id}/{decision}', name: 'leave_decide', requirements: ['id' => '\d+', 'decision' => 'approve|reject'], methods: ['POST'])]
    public function decide(int $id, string $decision, Request $request): Response
    {
        $leave = $this->load($id);
        if (!$this->events->canDecideLeave($this->getUser(), $leave)) {
            throw $this->createAccessDeniedException('You cannot decide on this leave');
        }

        if ($this->hasValidToken($request)) {
            try {
                $this->events->decideLeave($this->getUser(), $leave, $decision === 'approve', (string) $request->request->get('comment', ''));
                // approved leave is put into the person's timesheet, so they do not have to enter it again
                if ($decision === 'approve') {
                    $this->timesheetSync->sync($leave);
                } else {
                    $this->timesheetSync->remove($leave);
                }
                $this->flashSuccess('action.update.success');
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->redirectToRoute('leave');
    }

    #[Route(path: '/leave/{id}/cancel', name: 'leave_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $leave = $this->load($id);
        if (!$this->events->canCancelLeave($this->getUser(), $leave)) {
            throw $this->createAccessDeniedException('You cannot cancel this leave');
        }

        if ($this->hasValidToken($request)) {
            try {
                // the time entries that were created for this leave go with it
                $this->timesheetSync->remove($leave);
                $this->events->delete($leave);
                $this->flashSuccess('action.delete.success');
            } catch (\Exception $ex) {
                $this->flashDeleteException($ex);
            }
        }

        return $this->redirectToRoute('leave');
    }

    #[Route(path: '/holiday-calendar/', name: 'holiday_calendar', methods: ['GET'])]
    public function holidayCalendar(): Response
    {
        return $this->render('leave/holiday-calendar.html.twig', [
            'page_setup' => new PageSetup('Holiday Calendar'),
        ]);
    }

    #[Route(path: '/holiday-calendar/image', name: 'holiday_calendar_image', methods: ['GET'])]
    public function holidayCalendarImage(): Response
    {
        $file = $this->getParameter('kernel.project_dir') . self::HOLIDAY_CALENDAR;
        if (!is_file($file) || !is_readable($file)) {
            throw $this->createNotFoundException('The holiday calendar picture is missing');
        }

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'image/png');
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * The rules a leave request has to pass before it is sent to the manager.
     */
    private function validateLeave(FormInterface $form, TeamEvent $leave): void
    {
        $user = $this->getUser();
        $isOptional = $leave->getTitle() === 'Optional holiday';

        // An optional holiday is a single day, so it cannot go on a date that already has leave.
        // Other leave may run across days that are already covered: those days are simply not counted again
        // (see TeamEventService::getLeaveDays), and only the remaining days go for approval.
        if ($isOptional) {
            $existing = $this->events->findOverlappingLeave($user, $leave->getStartDate(), $leave->getEndDate());
            if ($existing !== []) {
                $list = [];
                foreach ($existing as $other) {
                    $dates = $other->getStartDate()->format('d-M-y') . ($other->isMultiDay() ? ' to ' . $other->getEndDate()->format('d-M-y') : '');
                    $list[] = $dates . ' (' . $other->getTitle() . ', ' . ($other->isPending() ? 'waiting for approval' : 'approved') . ')';
                }
                $form->get('startDate')->addError(new FormError('You already have leave on this date: ' . implode(', ', $list) . '.'));

                return;
            }
        }

        if ($isOptional) {
            // only a limited number of optional holidays per calendar year
            $year = (int) $leave->getStartDate()->format('Y');
            $limit = TeamEventService::OPTIONAL_HOLIDAYS_PER_YEAR;
            if (($this->events->getOptionalHolidaysUsed($user)[$year] ?? 0) >= $limit) {
                $form->get('title')->addError(new FormError(\sprintf(
                    'You have already used your %d optional holidays for %d. Cancel one of them first, or choose another leave type.',
                    $limit,
                    $year
                )));

                return;
            }

            // an optional holiday is one day, and only a date the calendar marks as optional for that office
            $location = (string) $form->get('location')->getData();
            if ($location === '') {
                $form->get('location')->addError(new FormError('Choose your office location to apply for an optional holiday.'));

                return;
            }
            if ($leave->isMultiDay()) {
                $form->get('endDate')->addError(new FormError('An optional holiday is a single day. Apply for each one separately.'));

                return;
            }

            $allowed = $this->calendar->getOptionalHolidays($location);
            $date = $leave->getStartDate()->format('Y-m-d');
            if (!isset($allowed[$date])) {
                $list = [];
                foreach ($allowed as $day => $name) {
                    $list[] = (new \DateTime($day))->format('d-M-y') . ' (' . $name . ')';
                }
                $form->get('startDate')->addError(new FormError(
                    'This date is not an optional holiday for ' . $location . '. Optional holidays there: ' . implode(', ', $list) . '.'
                ));

                return;
            }

            $reason = trim((string) $leave->getDescription());
            $leave->setDescription($allowed[$date] . ' (' . $location . ')' . ($reason !== '' ? "\n" . $reason : ''));

            return;
        }

        // leave is taken on working days: it cannot start or end on a Saturday or Sunday
        if ((int) $leave->getStartDate()->format('N') > 5) {
            $form->get('startDate')->addError(new FormError('Leave cannot start on a Saturday or Sunday.'));

            return;
        }
        if ((int) $leave->getEndDate()->format('N') > 5) {
            $form->get('endDate')->addError(new FormError('Leave cannot end on a Saturday or Sunday.'));

            return;
        }

        // nor on a day that is a holiday at every office
        $holidays = $this->calendar->getHolidaysEverywhere();
        foreach (['startDate' => $leave->getStartDate(), 'endDate' => $leave->getEndDate()] as $field => $date) {
            if (isset($holidays[$date->format('Y-m-d')])) {
                $form->get($field)->addError(new FormError(
                    $date->format('d-M-y') . ' is a holiday (' . $holidays[$date->format('Y-m-d')] . '), so no leave is needed for it.'
                ));

                return;
            }
        }

        // weekends, holidays and the person's own optional holidays inside the dates are not counted
        $leave->setUser($user);
        $days = $this->events->getLeaveDays($leave);
        if ($days === 0) {
            $form->get('startDate')->addError(new FormError('You already have leave or a holiday on all of these days, so there is nothing left to apply for.'));

            return;
        }

        if (!$this->events->countsAgainstBalance($leave)) {
            return;
        }

        // the yearly balance may go below zero, but only down to the overdraw limit
        $year = (int) $leave->getStartDate()->format('Y');
        $balance = $this->events->getLeaveBalance($user, $year);
        $limit = TeamEventService::LEAVE_OVERDRAW_DAYS;
        if ($balance - $days < -$limit) {
            $form->get('startDate')->addError(new FormError(\sprintf(
                'Not enough leave: your balance for %d is %s days and this request needs %d. The balance cannot go below -%d.',
                $year,
                rtrim(rtrim(number_format($balance, 1), '0'), '.'),
                $days,
                $limit
            )));
        }
    }

    private function hasValidToken(Request $request): bool
    {
        if ($this->isCsrfTokenValid('leave_action', (string) $request->request->get('_token'))) {
            return true;
        }

        $this->flashError('action.csrf.error');

        return false;
    }

    private function load(int $id): TeamEvent
    {
        $leave = $this->events->find($id);
        if ($leave === null || $leave->getType() !== TeamEvent::TYPE_LEAVE) {
            throw $this->createNotFoundException('Leave not found');
        }

        return $leave;
    }
}
