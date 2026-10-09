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
use App\Form\CompOffRequestForm;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\LeaveTimesheetSync;
use App\TeamEvent\TeamEventService;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comp-off (Apply Leave > Comp-Off): a day or half day off for working on a weekend, a festival or holiday,
 * or a night shift. The request goes to the person's manager - the same person who approves their leave.
 * Once approved it is leave like any other: it goes into the timesheet (Log Time, Calendar Entry, Bulk Entry (Week),
 * the bar graph) and the person's teammates see it on Notifications. A rejection needs a reason.
 * The person hears about the decision through the bell.
 *
 * A comp-off is stored as a leave request of the type "Comp off", with the day that was worked.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompOffController extends AbstractController
{
    /** how far back a worked day can be claimed */
    public const CLAIM_DAYS = 60;
    /** the comp-off has to be taken within this many days of the day worked */
    public const USE_WITHIN_DAYS = 60;
    public const FULL_DAY_HOURS = 8;
    public const HALF_DAY_HOURS = 4;

    public function __construct(
        private readonly TeamEventService $events,
        private readonly HolidayCalendar $calendar,
        private readonly LeaveTimesheetSync $timesheetSync,
    ) {
    }

    #[Route(path: '/comp-off/', name: 'comp_off', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $mine = $this->events->getMyLeave($user, true);
        $team = $this->events->getTeamLeave($user, false, true);

        $cancellable = [];
        foreach ($mine as $request) {
            $cancellable[$request->getId()] = $this->events->canCancelLeave($user, $request);
        }
        $decidable = [];
        foreach ($team as $request) {
            $decidable[$request->getId()] = $this->events->canDecideLeave($user, $request);
        }

        // the decisions on this page are no longer news for the bell
        $this->events->markDecisionsSeen($user, true);

        return $this->render('comp-off/index.html.twig', [
            'page_setup' => new PageSetup('Comp-Off'),
            'my_requests' => $mine,
            'team_requests' => $team,
            'cancellable' => $cancellable,
            'decidable' => $decidable,
        ]);
    }

    #[Route(path: '/comp-off/request', name: 'comp_off_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $user = $this->getUser();
        $compOff = new TeamEvent();
        $compOff->setType(TeamEvent::TYPE_LEAVE);
        $compOff->setTitle(TeamEvent::COMP_OFF);

        $form = $this->createForm(CompOffRequestForm::class, $compOff, [
            'action' => $this->generateUrl('comp_off_request'),
            'method' => 'POST',
            'claim_days' => self::CLAIM_DAYS,
            'use_within_days' => self::USE_WITHIN_DAYS,
            'full_day_hours' => self::FULL_DAY_HOURS,
            'half_day_hours' => self::HALF_DAY_HOURS,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $compOff->setTitle(TeamEvent::COMP_OFF);
            $compOff->setEndDate(clone $compOff->getStartDate());
            // the person's office decides which days are holidays
            $compOff->setUser($user);
            $this->validate($form, $compOff, $user);

            if ($form->isValid()) {
                try {
                    $this->events->applyForLeave($user, $compOff);
                    // a request that needs nobody's approval (an administrator's own) goes into the timesheet right away
                    $this->timesheetSync->sync($compOff);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('comp_off');
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        return $this->render('comp-off/request.html.twig', [
            'page_setup' => new PageSetup('Comp-Off'),
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/comp-off/{id}/{decision}', name: 'comp_off_decide', requirements: ['id' => '\d+', 'decision' => 'approve|reject'], methods: ['POST'])]
    public function decide(int $id, string $decision, Request $request): Response
    {
        $compOff = $this->load($id);
        if (!$this->events->canDecideLeave($this->getUser(), $compOff)) {
            throw $this->createAccessDeniedException('You cannot decide on this comp-off');
        }

        if ($this->hasValidToken($request)) {
            $comment = trim((string) $request->request->get('comment', ''));
            if ($decision === 'reject' && $comment === '') {
                // a rejected comp-off always says why
                $this->flashError('Please give the reason for rejecting the comp-off.');

                return $this->redirectToRoute('comp_off');
            }
            try {
                $this->events->decideLeave($this->getUser(), $compOff, $decision === 'approve', $comment);
                if ($decision === 'approve') {
                    $this->timesheetSync->sync($compOff);
                } else {
                    $this->timesheetSync->remove($compOff);
                }
                $this->flashSuccess('action.update.success');
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->redirectToRoute('comp_off');
    }

    #[Route(path: '/comp-off/{id}/cancel', name: 'comp_off_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $compOff = $this->load($id);
        if (!$this->events->canCancelLeave($this->getUser(), $compOff)) {
            throw $this->createAccessDeniedException('You cannot cancel this comp-off');
        }

        if ($this->hasValidToken($request)) {
            try {
                $this->timesheetSync->remove($compOff);
                $this->events->delete($compOff);
                $this->flashSuccess('action.delete.success');
            } catch (\Exception $ex) {
                $this->flashDeleteException($ex);
            }
        }

        return $this->redirectToRoute('comp_off');
    }

    /**
     * The rules of a comp-off request.
     */
    private function validate(FormInterface $form, TeamEvent $compOff, User $user): void
    {
        $today = new \DateTimeImmutable('today');
        $worked = $compOff->getCompWorkedDate();
        $day = $compOff->getStartDate();
        $hours = (float) $compOff->getCompWorkedHours();
        if ($worked === null || $day === null) {
            return;
        }
        $workedDay = $worked->format('Y-m-d');

        // the day worked: in the past (or today), not too long ago, and really a weekend, holiday or night shift
        if ($workedDay > $today->format('Y-m-d')) {
            $form->get('compWorkedDate')->addError(new FormError('The day you worked cannot be in the future.'));
        } elseif ($workedDay < $today->modify('-' . self::CLAIM_DAYS . ' days')->format('Y-m-d')) {
            $form->get('compWorkedDate')->addError(new FormError('A comp-off can be asked for up to ' . self::CLAIM_DAYS . ' days after the day worked.'));
        } elseif ($compOff->getCompReason() === 'weekend' && (int) $worked->format('N') < 6) {
            $form->get('compWorkedDate')->addError(new FormError($worked->format('d-M-y (l)') . ' is not a weekend day.'));
        } elseif ($compOff->getCompReason() === 'holiday' && !$this->isHoliday($user, $workedDay)) {
            $form->get('compWorkedDate')->addError(new FormError($worked->format('d-M-y') . ' is not a festival or holiday in the holiday calendar' . (HolidayCalendar::getUserLocation($user) !== null ? ' of your office' : '') . '.'));
        }

        // the hours worked: enough for what is asked
        if ($hours <= 0 || $hours > 14 || fmod($hours * 2, 1.0) !== 0.0) {
            $form->get('compWorkedHours')->addError(new FormError('Enter the hours worked between 0.5 and 14, in steps of 0.5.'));
        } elseif ($compOff->isCompHalfDay() && $hours < self::HALF_DAY_HOURS) {
            $form->get('compWorkedHours')->addError(new FormError('A half day off needs at least ' . self::HALF_DAY_HOURS . ' hours worked.'));
        } elseif (!$compOff->isCompHalfDay() && $hours < self::FULL_DAY_HOURS) {
            $form->get('compWorkedHours')->addError(new FormError('A full day off needs at least ' . self::FULL_DAY_HOURS . ' hours worked. Ask for a half day instead.'));
        }

        // the day off: a working day after the day worked, within the limit, without other leave
        $dayOff = $day->format('Y-m-d');
        $latest = \DateTimeImmutable::createFromInterface($worked)->modify('+' . self::USE_WITHIN_DAYS . ' days')->format('Y-m-d');
        if ($dayOff <= $workedDay) {
            $form->get('startDate')->addError(new FormError('The comp-off has to be after the day you worked.'));
        } elseif ($dayOff > $latest) {
            $form->get('startDate')->addError(new FormError('The comp-off has to be taken within ' . self::USE_WITHIN_DAYS . ' days of the day worked (by ' . (new \DateTimeImmutable($latest))->format('d-M-y') . ').'));
        } elseif ($this->events->getLeaveDates($compOff) === []) {
            $form->get('startDate')->addError(new FormError($day->format('d-M-y (l)') . ' is not a working day: pick a working day for the comp-off.'));
        } elseif ($this->events->findOverlappingLeave($user, $day, $day) !== []) {
            $form->get('startDate')->addError(new FormError('You already have leave or a comp-off on ' . $day->format('d-M-y') . '.'));
        }

        // a worked day is claimed once
        foreach ($this->events->getMyLeave($user, true) as $earlier) {
            if ($earlier->getStatus() !== TeamEvent::STATUS_REJECTED && $earlier->getCompWorkedDate()?->format('Y-m-d') === $workedDay) {
                $form->get('compWorkedDate')->addError(new FormError('You already asked for a comp-off for ' . $worked->format('d-M-y') . ' (comp-off on ' . $earlier->getStartDate()->format('d-M-y') . ').'));
                break;
            }
        }
    }

    /**
     * A festival day or holiday (or optional holiday) at the person's office; without an office, at any office.
     */
    private function isHoliday(User $user, string $date): bool
    {
        $day = $this->calendar->getDaysByLocation()[$date] ?? null;
        if ($day === null) {
            return false;
        }
        $office = HolidayCalendar::getUserLocation($user);
        foreach ($day['codes'] as $location => $code) {
            if (($office === null || $location === $office) && \in_array($code, [HolidayCalendar::HOLIDAY, HolidayCalendar::OPTIONAL], true)) {
                return true;
            }
        }

        return false;
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
        $compOff = $this->events->find($id);
        if ($compOff === null || !$compOff->isCompOff()) {
            throw $this->createNotFoundException('Comp-off not found');
        }

        return $compOff;
    }
}
