<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\TeamEvent;
use App\Form\TeamEventEditForm;
use App\Holiday\HolidayCalendar;
use App\TeamEvent\TeamEventService;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Events: team outings, holidays, leave and the like, shown on people's dashboards.
 */
#[Route(path: '/events')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TeamEventController extends AbstractController
{
    /** festival and optional holidays are listed from this many days before */
    private const HOLIDAY_NOTICE_DAYS = 7;

    public function __construct(
        private readonly TeamEventService $events,
        private readonly HolidayCalendar $holidays,
    ) {
    }

    #[Route(path: '/', name: 'team_events', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $today = new \DateTime('today');
        $upcoming = $this->events->getEventsForUser($user, $today, new \DateTime('+5 years'));
        $past = $this->events->getPastEventsForUser($user);

        $editable = [];
        foreach (array_merge($upcoming, $past) as $event) {
            $editable[$event->getId()] = $this->events->canEdit($user, $event);
        }

        // festival and optional holidays from a week before until the day itself is over;
        // a person with an office location only sees what is a holiday or optional holiday at that office
        $myOffice = HolidayCalendar::getUserLocation($user);
        $last = (new \DateTime('today'))->modify('+' . self::HOLIDAY_NOTICE_DAYS . ' days')->format('Y-m-d');
        $holidays = [];
        foreach ($this->holidays->getDaysByLocation() as $date => $day) {
            if ($date < $today->format('Y-m-d') || $date > $last) {
                continue;
            }
            if ($myOffice !== null) {
                $code = $day['codes'][$myOffice] ?? HolidayCalendar::WORKING;
                if ($code === HolidayCalendar::HOLIDAY || $code === HolidayCalendar::OPTIONAL) {
                    $holidays[] = [
                        'date' => new \DateTime($date),
                        'name' => $day['name'],
                        'mine' => $code === HolidayCalendar::HOLIDAY ? 'Holiday' : 'Optional holiday',
                    ];
                }
                continue;
            }
            $where = [HolidayCalendar::HOLIDAY => [], HolidayCalendar::OPTIONAL => []];
            foreach ($day['codes'] as $location => $code) {
                if (isset($where[$code])) {
                    $where[$code][] = $location;
                }
            }
            if ($where[HolidayCalendar::HOLIDAY] === [] && $where[HolidayCalendar::OPTIONAL] === []) {
                continue;
            }
            $holidays[] = [
                'date' => new \DateTime($date),
                'name' => $day['name'],
                'holiday_at' => $where[HolidayCalendar::HOLIDAY],
                'optional_at' => $where[HolidayCalendar::OPTIONAL],
                'everywhere' => \count($where[HolidayCalendar::HOLIDAY]) === \count(HolidayCalendar::LOCATIONS),
            ];
        }

        return $this->render('events/index.html.twig', [
            'page_setup' => new PageSetup('Notifications'),
            'team_leave' => $this->events->getTeammateLeave($user),
            'holidays' => $holidays,
            'my_office' => $myOffice,
            'upcoming' => $upcoming,
            'past' => $past,
            'editable' => $editable,
            'can_manage' => $this->events->canManage($user),
            'today' => $today->format('Y-m-d'),
        ]);
    }

    #[Route(path: '/create', name: 'team_events_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if (!$this->events->canManage($user)) {
            throw $this->createAccessDeniedException('Only managers and leads can add events');
        }

        $event = new TeamEvent();
        $event->setCreatedBy($user);
        // no default dates: a pre-filled "To" would stay on today when a later "From" is picked

        return $this->edit($event, $request, $this->generateUrl('team_events_create'));
    }

    #[Route(path: '/{id}/edit', name: 'team_events_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function update(int $id, Request $request): Response
    {
        $event = $this->load($id);
        if (!$this->events->canEdit($this->getUser(), $event)) {
            throw $this->createAccessDeniedException('You cannot change this event');
        }

        return $this->edit($event, $request, $this->generateUrl('team_events_edit', ['id' => $id]));
    }

    #[Route(path: '/{id}/delete', name: 'team_events_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $event = $this->load($id);
        if (!$this->events->canEdit($this->getUser(), $event)) {
            throw $this->createAccessDeniedException('You cannot delete this event');
        }

        if (!$this->isCsrfTokenValid('team_event_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('team_events');
        }

        try {
            $this->events->delete($event);
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $this->redirectToRoute('team_events');
    }

    private function load(int $id): TeamEvent
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found');
        }

        return $event;
    }

    private function edit(TeamEvent $event, Request $request, string $action): Response
    {
        $user = $this->getUser();

        // who the event can be for: "all" (administrators only), a team, or a person
        $teams = [];
        foreach ($this->events->getTeams($user) as $team) {
            $teams['t:' . $team->getId()] = $team;
        }
        $people = [];
        foreach ($this->events->getPeople($user) as $person) {
            $people['u:' . $person->getId()] = $person;
        }
        // keep the current choice available when editing
        if ($event->getTeam() !== null) {
            $teams['t:' . $event->getTeam()->getId()] = $event->getTeam();
        }
        if ($event->getUser() !== null) {
            $people['u:' . $event->getUser()->getId()] = $event->getUser();
        }

        $audiences = [];
        $allowEveryone = $this->events->isAdmin($user) || ($event->getId() !== null && $event->getTeam() === null && $event->getUser() === null && !$event->isAllMyTeams());
        if ($allowEveryone) {
            $audiences['Everyone'] = 'all';
        }
        // every team of the person adding the event, in one go
        $ownTeams = [];
        foreach ($user->getTeams() as $team) {
            $ownTeams[] = (string) $team->getName();
        }
        sort($ownTeams);
        $allowMyTeams = \count($ownTeams) > 1 || $event->isAllMyTeams();
        if ($allowMyTeams) {
            $audiences['All my teams (' . implode(', ', $ownTeams) . ')'] = 'my_teams';
        }
        foreach ($teams as $key => $team) {
            $audiences['Teams'][(string) $team->getName()] = $key;
        }
        foreach ($people as $key => $person) {
            $audiences['People'][$person->getDisplayName()] = $key;
        }

        $current = null;
        if ($event->getUser() !== null) {
            $current = 'u:' . $event->getUser()->getId();
        } elseif ($event->getTeam() !== null) {
            $current = 't:' . $event->getTeam()->getId();
        } elseif ($event->isAllMyTeams()) {
            $current = 'my_teams';
        } elseif ($event->getId() !== null) {
            $current = 'all';
        }

        // leave and holiday are not offered for new events; an existing event keeps its own type selectable
        $types = [];
        foreach (TeamEvent::EVENT_FORM_TYPES as $type) {
            $types[TeamEvent::TYPES[$type]] = $type;
        }
        if (!\in_array($event->getType(), TeamEvent::EVENT_FORM_TYPES, true) && $event->getId() !== null) {
            $types[$event->getTypeLabel()] = $event->getType();
        }

        $form = $this->createForm(TeamEventEditForm::class, $event, [
            'action' => $action,
            'method' => 'POST',
            'audiences' => $audiences,
            'audience' => $current,
            'types' => $types,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $audience = (string) $form->get('audience')->getData();
            $event->setTeam(null);
            $event->setUser(null);
            $event->setAllMyTeams(false);
            if ($audience === 'my_teams' && $allowMyTeams) {
                $event->setAllMyTeams(true);
            } elseif (isset($teams[$audience])) {
                $event->setTeam($teams[$audience]);
            } elseif (isset($people[$audience])) {
                $event->setUser($people[$audience]);
            } elseif ($audience !== 'all' || !$allowEveryone) {
                $form->get('audience')->addError(new FormError('Please choose who this event applies to.'));
            }

            // no last day given: a single-day event
            $lastDay = $form->get('endDate')->getData();
            if ($lastDay === null) {
                $event->setEndDate($event->getStartDate() !== null ? clone $event->getStartDate() : null);
            } elseif ($event->getStartDate() !== null && $lastDay->format('Y-m-d') < $event->getStartDate()->format('Y-m-d')) {
                $event->setEndDate(clone $lastDay);
                $form->get('endDate')->addError(new FormError('The last day cannot be before the first day.'));
            } else {
                // also covers "last day = first day", which the form itself does not write back
                $event->setEndDate(clone $lastDay);
            }

            // times are optional, but an end time needs a start time and has to come after it on a single day
            if ($event->getEndTime() !== null && $event->getStartTime() === null) {
                $form->get('startTime')->addError(new FormError('Enter a start time as well, or clear the end time.'));
            } elseif ($event->getStartTime() !== null && $event->getEndTime() !== null && !$event->isMultiDay()
                && $event->getEndTime()->format('H:i') <= $event->getStartTime()->format('H:i')) {
                $form->get('endTime')->addError(new FormError('The end time has to be after the start time.'));
            }

            if ($form->isValid()) {
                try {
                    $this->events->save($event);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('team_events');
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        return $this->render('events/edit.html.twig', [
            'page_setup' => new PageSetup('Notifications'),
            'event' => $event,
            'form' => $form->createView(),
        ]);
    }
}
