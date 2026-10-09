<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\AdditionalHours;
use App\Entity\User;
use App\Form\AdditionalHoursForm;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\AdditionalHoursService;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Additional Hours (Apply Leave > Additional Hours): hours worked on a weekend, a festival or holiday, or a night shift.
 * They go to the person's manager; approved ones are a comp-off credit (quarter, half or full day) that can be
 * taken as "Comp off" leave within 30 days. A rejection needs a reason.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AdditionalHoursController extends AbstractController
{
    public function __construct(
        private readonly AdditionalHoursService $service,
        private readonly HolidayCalendar $calendar,
    ) {
    }

    #[Route(path: '/additional-hours/', name: 'additional_hours', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $mine = $this->service->getMine($user);
        $team = $this->service->getTeamRequests($user);

        $usedBy = [];
        $cancellable = [];
        foreach ($mine as $hours) {
            $usedBy[$hours->getId()] = $hours->isApproved() ? $this->service->getUsedBy($hours) : null;
            $cancellable[$hours->getId()] = $this->service->canCancel($user, $hours);
        }
        $decidable = [];
        foreach ($team as $hours) {
            $decidable[$hours->getId()] = $this->service->canDecide($user, $hours);
        }
        // the decisions on this page are no longer news for the bell
        $this->service->markDecisionsSeen($user);

        return $this->render('additional-hours/index.html.twig', [
            'page_setup' => new PageSetup('Extra Hours Claim'),
            'mine' => $mine,
            'team' => $team,
            'used_by' => $usedBy,
            'cancellable' => $cancellable,
            'decidable' => $decidable,
            'available' => $this->service->getAvailableCredits($user),
        ]);
    }

    #[Route(path: '/additional-hours/new', name: 'additional_hours_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        $hours = new AdditionalHours();
        $hours->setUser($user);

        $form = $this->createForm(AdditionalHoursForm::class, $hours, [
            'action' => $this->generateUrl('additional_hours_new'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hours->setHours((float) $hours->getHours());
            $this->validate($form, $hours, $user);
            if ($form->isValid()) {
                try {
                    $this->service->save($hours);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('additional_hours');
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        return $this->render('additional-hours/new.html.twig', [
            'page_setup' => new PageSetup('Extra Hours Claim'),
            'form' => $form->createView(),
            'day_hours' => AdditionalHours::DAY_HOURS,
        ]);
    }

    /**
     * Has the person logged time on that day? Asked before the request is sent.
     */
    #[Route(path: '/additional-hours/check-time', name: 'additional_hours_check_time', methods: ['GET'])]
    public function checkTime(Request $request): JsonResponse
    {
        try {
            $day = new \DateTime(substr((string) $request->query->get('date'), 0, 10));
        } catch (\Exception) {
            return new JsonResponse(['logged' => true]);
        }

        return new JsonResponse([
            'logged' => $this->service->hasTimesheet($this->getUser(), $day),
            'label' => $day->format('d-M-y (l)'),
            'create' => $this->generateUrl('timesheet_create', ['begin' => $day->format('Y-m-d') . 'T09:00:00']),
        ]);
    }

    #[Route(path: '/additional-hours/{id}/{decision}', name: 'additional_hours_decide', requirements: ['id' => '\d+', 'decision' => 'approve|reject'], methods: ['POST'])]
    public function decide(int $id, string $decision, Request $request): Response
    {
        $hours = $this->load($id);
        if (!$this->service->canDecide($this->getUser(), $hours)) {
            throw $this->createAccessDeniedException('You cannot decide on these hours');
        }
        if ($this->hasValidToken($request) && $hours->isPending()) {
            $comment = trim((string) $request->request->get('comment', ''));
            if ($decision === 'reject' && $comment === '') {
                $this->flashError('Please give the reason for rejecting.');

                return $this->redirectToRoute('additional_hours');
            }
            $this->service->decide($this->getUser(), $hours, $decision === 'approve', $comment);
            $this->flashSuccess('action.update.success');
        }

        return $this->redirectToRoute('additional_hours');
    }

    #[Route(path: '/additional-hours/{id}/cancel', name: 'additional_hours_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $hours = $this->load($id);
        if (!$this->service->canCancel($this->getUser(), $hours)) {
            throw $this->createAccessDeniedException('You cannot cancel these hours');
        }
        if ($this->hasValidToken($request)) {
            $this->service->delete($hours);
            $this->flashSuccess('action.delete.success');
        }

        return $this->redirectToRoute('additional_hours');
    }

    private function validate(FormInterface $form, AdditionalHours $hours, User $user): void
    {
        $date = $hours->getWorkDate();
        if ($date === null) {
            return;
        }
        $day = $date->format('Y-m-d');
        $today = new \DateTimeImmutable('today');

        if ($day > $today->format('Y-m-d')) {
            $form->get('workDate')->addError(new FormError('The day cannot be in the future.'));
        } elseif ($day < $today->modify('-' . AdditionalHours::CREDIT_VALID_DAYS . ' days')->format('Y-m-d')) {
            $form->get('workDate')->addError(new FormError('Extra hours can be claimed up to ' . AdditionalHours::CREDIT_VALID_DAYS . ' days back.'));
        } elseif ($hours->getReason() === 'weekend' && (int) $date->format('N') < 6) {
            $form->get('workDate')->addError(new FormError($date->format('d-M-y (l)') . ' is not a weekend day.'));
        } elseif ($hours->getReason() === 'holiday' && !$this->isHoliday($user, $day)) {
            $form->get('workDate')->addError(new FormError($date->format('d-M-y') . ' is not a festival or holiday in the holiday calendar' . (HolidayCalendar::getUserLocation($user) !== null ? ' of your office' : '') . '.'));
        }

        $value = (float) $hours->getHours();
        if ($value <= 0 || $value > 24 || fmod($value * 4, 1.0) !== 0.0) {
            $form->get('hours')->addError(new FormError('Enter the hours between 0.25 and 24, in steps of 0.25.'));
        } elseif ($hours->getCreditDays() <= 0) {
            $form->get('hours')->addError(new FormError('At least ' . (AdditionalHours::DAY_HOURS / 4) . ' hours make a quarter day.'));
        }

        foreach ($this->service->getMine($user) as $earlier) {
            if ($earlier->getStatus() !== AdditionalHours::STATUS_REJECTED && $earlier->getWorkDate()?->format('Y-m-d') === $day) {
                $form->get('workDate')->addError(new FormError('You already claimed extra hours for ' . $date->format('d-M-y') . '.'));
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
        if ($this->isCsrfTokenValid('additional_hours_action', (string) $request->request->get('_token'))) {
            return true;
        }
        $this->flashError('action.csrf.error');

        return false;
    }

    private function load(int $id): AdditionalHours
    {
        $hours = $this->service->find($id);
        if ($hours === null) {
            throw $this->createNotFoundException('Not found');
        }

        return $hours;
    }
}
