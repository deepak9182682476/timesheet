<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\WfhRequest;
use App\Form\WfhRequestForm;
use App\TeamEvent\WfhService;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * WFH Request (Apply Leave > WFH Request): a day or a few days of work from home, approved by the person's
 * immediate supervisor. Approved days count as worked although the attendance says "not present" (see
 * AttendanceService). A rejection needs a reason.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class WfhController extends AbstractController
{
    /** the longest request, in days */
    private const MAX_DAYS = 31;

    public function __construct(private readonly WfhService $service)
    {
    }

    #[Route(path: '/wfh/', name: 'wfh', methods: ['GET'])]
    public function index(): Response
    {
        // WFH requests are now on the Leave & Comp-off page (leave type "Work from home")
        return $this->redirectToRoute('leave', ['_fragment' => 'my-wfh']);
    }

    /** the separate WFH Request page, before it moved to Leave & Comp-off (not used any more) */
    public function indexBefore(): Response
    {
        $user = $this->getUser();
        $mine = $this->service->getMine($user);
        $team = $this->service->getTeamRequests($user);

        $cancellable = [];
        foreach ($mine as $request) {
            $cancellable[$request->getId()] = $this->service->canCancel($user, $request);
        }
        $decidable = [];
        foreach ($team as $request) {
            $decidable[$request->getId()] = $this->service->canDecide($user, $request);
        }
        // the decisions on this page are no longer news for the bell
        $this->service->markDecisionsSeen($user);

        return $this->render('wfh/index.html.twig', [
            'page_setup' => new PageSetup('WFH Request'),
            'mine' => $mine,
            'team' => $team,
            'cancellable' => $cancellable,
            'decidable' => $decidable,
        ]);
    }

    #[Route(path: '/wfh/new', name: 'wfh_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        // asked for in Apply for Leave now, with the leave type "Work from home"
        return $this->redirectToRoute('leave_apply');
    }

    /** the separate WFH Request form, before it moved to Apply for Leave (not used any more) */
    public function createBefore(Request $request): Response
    {
        $user = $this->getUser();
        $wfh = new WfhRequest();
        $wfh->setUser($user);

        $form = $this->createForm(WfhRequestForm::class, $wfh, [
            'action' => $this->generateUrl('wfh_new'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($wfh->getEndDate() === null && $wfh->getStartDate() !== null) {
                $wfh->setEndDate(clone $wfh->getStartDate());
            }
            $this->validate($form, $wfh);
            if ($form->isValid()) {
                try {
                    $this->service->save($wfh);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('wfh');
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        // the days already asked for (not rejected), for the check in the browser
        $taken = [];
        foreach ($this->service->getMine($user) as $earlier) {
            if ($earlier->getStatus() !== WfhRequest::STATUS_REJECTED) {
                $taken[] = ['from' => $earlier->getStartDate()->format('Y-m-d'), 'to' => $earlier->getEndDate()->format('Y-m-d'), 'message' => $this->takenMessage($earlier)];
            }
        }

        return $this->render('wfh/new.html.twig', [
            'page_setup' => new PageSetup('WFH Request'),
            'form' => $form->createView(),
            'taken' => $taken,
            'days_back' => WfhRequest::DAYS_BACK,
            'max_days' => self::MAX_DAYS,
        ]);
    }

    #[Route(path: '/wfh/{id}/{decision}', name: 'wfh_decide', requirements: ['id' => '\d+', 'decision' => 'approve|reject'], methods: ['POST'])]
    public function decide(int $id, string $decision, Request $request): Response
    {
        $wfh = $this->load($id);
        if (!$this->service->canDecide($this->getUser(), $wfh)) {
            throw $this->createAccessDeniedException('You cannot decide on this request');
        }
        if ($this->hasValidToken($request) && $wfh->isPending()) {
            $comment = trim((string) $request->request->get('comment', ''));
            if ($decision === 'reject' && $comment === '') {
                $this->flashError('Please give the reason for rejecting.');

                return $this->redirectToRoute('leave', ['_fragment' => 'team-wfh']);
            }
            $this->service->decide($this->getUser(), $wfh, $decision === 'approve', $comment);
            $this->flashSuccess('action.update.success');
        }

        // return $this->redirectToRoute('wfh');
        return $this->redirectToRoute('leave', ['_fragment' => 'team-wfh']);
    }

    #[Route(path: '/wfh/{id}/cancel', name: 'wfh_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $wfh = $this->load($id);
        if (!$this->service->canCancel($this->getUser(), $wfh)) {
            throw $this->createAccessDeniedException('You cannot cancel this request');
        }
        if ($this->hasValidToken($request)) {
            $this->service->delete($wfh);
            $this->flashSuccess('action.delete.success');
        }

        // return $this->redirectToRoute('wfh');
        return $this->redirectToRoute('leave', ['_fragment' => 'my-wfh']);
    }

    private function validate(\Symfony\Component\Form\FormInterface $form, WfhRequest $wfh): void
    {
        $from = $wfh->getStartDate();
        $to = $wfh->getEndDate();
        if ($from === null || $to === null) {
            return;
        }
        $oldest = (new \DateTimeImmutable('today'))->modify('-' . WfhRequest::DAYS_BACK . ' days')->format('Y-m-d');

        if ($from->format('Y-m-d') < $oldest) {
            $form->get('startDate')->addError(new FormError('WFH can be asked for up to ' . WfhRequest::DAYS_BACK . ' days back.'));

            return;
        }
        if ($to->format('Y-m-d') < $from->format('Y-m-d')) {
            $form->get('endDate')->addError(new FormError('The last day is before the first day.'));

            return;
        }
        if ($wfh->getDays() > self::MAX_DAYS) {
            $form->get('endDate')->addError(new FormError('A request can cover at most ' . self::MAX_DAYS . ' days.'));

            return;
        }
        $overlap = $this->service->findOverlapping($wfh->getUser(), $from, $to, $wfh);
        if ($overlap !== []) {
            $form->get('startDate')->addError(new FormError($this->takenMessage($overlap[0])));
        }
    }

    private function takenMessage(WfhRequest $earlier): string
    {
        $status = $earlier->isApproved() ? 'Approved' : 'Waiting for approval';
        $days = $earlier->getStartDate()->format('d-M-y') . ($earlier->isMultiDay() ? ' to ' . $earlier->getEndDate()->format('d-M-y') : '');

        return 'You already asked to work from home on ' . $days . ' (' . $status . '). The same day cannot be asked for twice.';
    }

    private function hasValidToken(Request $request): bool
    {
        if ($this->isCsrfTokenValid('wfh_action', (string) $request->request->get('_token'))) {
            return true;
        }
        $this->flashError('action.csrf.error');

        return false;
    }

    private function load(int $id): WfhRequest
    {
        $wfh = $this->service->find($id);
        if ($wfh === null) {
            throw $this->createNotFoundException('Not found');
        }

        return $wfh;
    }
}
