<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Team;
use App\Entity\User;
use App\Team\TeamMappingService;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Team Mapping": Project Managers and Project Leads put their teams together - which projects a team works on,
 * who is in it and what each person does there. See TeamMappingService.
 */
#[Route(path: '/team-mapping')]
#[IsGranted('ROLE_TEAMLEAD')]
final class TeamMappingController extends AbstractController
{
    private const TOKEN = 'team_mapping';
    private const NOTICE = 'team_mapping_notice';

    public function __construct(
        private readonly TeamMappingService $teams,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly \App\Team\AllocationService $allocations,
    ) {
    }

    #[Route(path: '/', name: 'team_mapping', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $teams = $this->teams->getTeams($user);

        $selected = null;
        $wanted = $request->query->getInt('team');
        foreach ($teams as $team) {
            if ($team->getId() === $wanted) {
                $selected = $team;
            }
        }
        if ($selected === null && $teams !== [] && !$request->query->has('new')) {
            $selected = $teams[0];
        }

        $members = [];
        if ($selected !== null) {
            foreach ($selected->getMembers() as $member) {
                if ($member->getUser() !== null) {
                    $members[] = $member;
                }
            }
            // leads first, then by name
            usort($members, static function ($a, $b): int {
                if ($a->isTeamlead() !== $b->isTeamlead()) {
                    return $a->isTeamlead() ? -1 : 1;
                }

                return strcasecmp($a->getUser()->getDisplayName(), $b->getUser()->getDisplayName());
            });
        }

        $linked = [];
        if ($selected !== null) {
            foreach ($selected->getProjects() as $project) {
                $linked[] = (int) $project->getId();
            }
        }

        // allocation ID and periods (Allocation No. 1, 2 ...) of every person on every project of the team
        $linkedProjects = [];
        $allProjects = $this->teams->getProjects();
        foreach ($linked as $projectId) {
            $linkedProjects[$projectId] = $allProjects[$projectId] ?? ('#' . $projectId);
        }
        $allocations = $this->allocations->getAllocations($linked, array_map(static fn ($m) => (int) $m->getUser()->getId(), $members));

        $session = $request->getSession();
        $notice = $session->get(self::NOTICE);
        $session->remove(self::NOTICE);

        return $this->render('team-mapping/index.html.twig', [
            'page_setup' => new PageSetup('Team Allocation'),
            'notice' => \is_string($notice) ? $notice : null,
            'teams' => $teams,
            'selected' => $selected,
            'members' => $members,
            'linked' => $linked,
            'creating' => $selected === null,
            'projects' => $this->teams->getProjects(),
            // the dates of each project come from the project itself (Administration > Projects) and are only shown here
            'project_dates' => $this->teams->getProjectDates(),
            // to say at once that a team name is taken (another team of that name, whatever the capitals)
            'taken_names' => array_values(array_map(
                [TeamMappingService::class, 'nameKey'],
                array_filter($this->teams->getTeamNames(), static fn (int $id) => $selected === null || $id !== $selected->getId(), \ARRAY_FILTER_USE_KEY)
            )),
            'people' => $this->teams->getPeople(),
            'roles' => $this->teams->getRoles(),
            'token' => self::TOKEN,
            'linked_projects' => $linkedProjects,
            'allocations' => $allocations,
        ]);
    }

    #[Route(path: '/create', name: 'team_mapping_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->validToken($request)) {
            return $this->redirectToRoute('team_mapping', ['new' => 1]);
        }

        $name = trim((string) $request->request->get('name'));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->flashError('Enter a team name of up to 100 characters.');

            return $this->redirectToRoute('team_mapping', ['new' => 1]);
        }
        if ($this->teams->nameTaken($name)) {
            // $this->flashError('A team called "' . $name . '" exists already. Choose another name.');
            $this->flashError('Team name "' . $name . '" already exists. Choose another name.');

            return $this->redirectToRoute('team_mapping', ['new' => 1]);
        }

        $id = $this->teams->createTeam($this->getUser(), $name, $this->ids($request->request->all('projects')), $this->roles($request, 'my_role'));
        $this->flashInfo('Team "' . $name . '" is created. Now add the people who work on it.');

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
    }

    #[Route(path: '/{id}/update', name: 'team_mapping_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $team = $this->load($id);
        if ($this->validToken($request)) {
            $name = trim((string) $request->request->get('name'));
            if ($name === '' || mb_strlen($name) > 100) {
                $this->flashError('Enter a team name of up to 100 characters.');
            } elseif ($this->teams->nameTaken($name, $id)) {
                // $this->flashError('A team called "' . $name . '" exists already. Choose another name.');
                $this->flashError('Team name "' . $name . '" already exists. Choose another name.');
            } else {
                $this->teams->updateTeam($team, $name, $this->ids($request->request->all('projects')));
                $this->flashInfo('Team "' . $name . '" is saved.');
            }
        }

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
    }

    #[Route(path: '/{id}/delete', name: 'team_mapping_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $team = $this->load($id);
        if ($this->validToken($request)) {
            $name = (string) $team->getName();
            $this->teams->deleteTeam($team);
            $this->flashInfo('Team "' . $name . '" is deleted.');
        }

        return $this->redirectToRoute('team_mapping');
    }

    #[Route(path: '/{id}/member', name: 'team_mapping_member', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function member(int $id, Request $request): Response
    {
        $team = $this->load($id);
        if (!$this->validToken($request)) {
            return $this->redirectToRoute('team_mapping', ['team' => $id]);
        }

        $person = $this->entityManager->find(User::class, $request->request->getInt('user'));
        if ($person === null || !$person->isEnabled()) {
            $this->flashError('Choose the person to add.');

            return $this->redirectToRoute('team_mapping', ['team' => $id]);
        }

        // $lead = $request->request->getBoolean('lead');
        // the "Team lead" column is hidden: whoever leads the team stays lead, everybody added is a member
        $lead = $request->request->has('lead') ? $request->request->getBoolean('lead') : $this->teams->isLead($team, $person);
        if (!$lead && $this->teams->isLastLead($team, $person)) {
            $lead = true;
            $this->flashWarning($person->getDisplayName() . ' stays lead: a team needs at least one lead.');
        }
        // somebody new on the team: the allocation dates (from and to) are needed for each project of the team
        $isNew = true;
        foreach ($team->getMembers() as $member) {
            if ($member->getUser()?->getId() === $person->getId()) {
                $isNew = false;
            }
        }
        $from = (string) $request->request->get('alloc_from', '');
        $to = (string) $request->request->get('alloc_to', '');
        $projects = $this->linkedProjects($team);
        if ($isNew && $projects !== []) {
            $dates = $this->teams->getProjectDates();
            foreach ($projects as $projectId => $projectName) {
                $problem = $this->allocations->check($projectId, $projectName, $dates[$projectId] ?? ['start' => null, 'end' => null], (int) $person->getId(), $from, $to);
                if ($problem !== null) {
                    $this->flashError($person->getDisplayName() . ' is not added. ' . $problem);

                    return $this->redirectToRoute('team_mapping', ['team' => $id]);
                }
            }
        }

        $this->teams->saveMember($team, $person, $this->roles($request, 'role'), $lead);
        $added = [];
        if ($isNew) {
            foreach ($projects as $projectId => $projectName) {
                $period = $this->allocations->addPeriod($projectId, (int) $person->getId(), $from, $to, $this->getUser());
                $added[] = $projectName . ': ' . $period['code'] . ' Allocation No. ' . $period['number'];
            }
        }
        $this->flashInfo($person->getDisplayName() . ' is saved in team "' . $team->getName() . '".' . ($added !== [] ? ' ' . implode(', ', $added) . '.' : ''));

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
    }

    /**
     * Another period on a project (the next Allocation No. of the person's allocation).
     */
    #[Route(path: '/{id}/allocation', name: 'team_mapping_allocation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function allocation(int $id, Request $request): Response
    {
        $team = $this->load($id);
        if (!$this->validToken($request)) {
            return $this->redirectToRoute('team_mapping', ['team' => $id]);
        }
        $person = $this->entityManager->find(User::class, $request->request->getInt('user'));
        $projectId = $request->request->getInt('project');
        $projects = $this->linkedProjects($team);
        $inTeam = false;
        foreach ($team->getMembers() as $member) {
            if ($person !== null && $member->getUser()?->getId() === $person->getId()) {
                $inTeam = true;
            }
        }
        if ($person === null || !$inTeam || !isset($projects[$projectId])) {
            $this->flashError('Choose the person and the project of this team.');

            return $this->redirectToRoute('team_mapping', ['team' => $id]);
        }
        $from = (string) $request->request->get('alloc_from', '');
        $to = (string) $request->request->get('alloc_to', '');
        $dates = $this->teams->getProjectDates();
        $problem = $this->allocations->check($projectId, $projects[$projectId], $dates[$projectId] ?? ['start' => null, 'end' => null], (int) $person->getId(), $from, $to);
        if ($problem !== null) {
            $this->flashError($problem);
        } else {
            $period = $this->allocations->addPeriod($projectId, (int) $person->getId(), $from, $to, $this->getUser());
            $this->flashInfo($person->getDisplayName() . ' on ' . $projects[$projectId] . ': ' . $period['code'] . ' Allocation No. ' . $period['number'] . ' is saved.');
        }

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
    }

    #[Route(path: '/{id}/allocation/{period}/remove', name: 'team_mapping_allocation_remove', requirements: ['id' => '\d+', 'period' => '\d+'], methods: ['POST'])]
    public function removeAllocation(int $id, int $period, Request $request): Response
    {
        $team = $this->load($id);
        if ($this->validToken($request)) {
            $removed = $this->allocations->removePeriod($period, array_keys($this->linkedProjects($team)));
            if ($removed !== null) {
                $this->flashInfo($removed['code'] . ' Allocation No. ' . $removed['number'] . ' is removed.');
            }
        }

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
    }

    /**
     * @return array<int, string> the team's projects, name keyed by ID
     */
    private function linkedProjects(Team $team): array
    {
        $projects = [];
        foreach ($team->getProjects() as $project) {
            $projects[(int) $project->getId()] = (string) $project->getName();
        }

        return $projects;
    }

    #[Route(path: '/{id}/member/{user}/remove', name: 'team_mapping_member_remove', requirements: ['id' => '\d+', 'user' => '\d+'], methods: ['POST'])]
    public function removeMember(int $id, int $user, Request $request): Response
    {
        $team = $this->load($id);
        $person = $this->entityManager->find(User::class, $user);
        if ($person !== null && $this->validToken($request)) {
            if ($this->teams->isLastLead($team, $person)) {
                $this->flashError($person->getDisplayName() . ' is the only lead of this team and cannot be taken out. Make somebody else lead first.');
            } else {
                $this->teams->removeMember($team, $person);
                $this->flashInfo($person->getDisplayName() . ' is taken out of team "' . $team->getName() . '".');
            }
        }

        // somebody who took themselves out cannot see the team any more
        $after = $this->entityManager->find(Team::class, $id);

        return $this->redirectToRoute('team_mapping', $after !== null && $this->teams->canChange($this->getUser(), $after) ? ['team' => $id] : []);
    }

    private function load(int $id, bool $check = true): ?Team
    {
        $team = $this->entityManager->find(Team::class, $id);
        if ($team === null) {
            throw $this->createNotFoundException('Team not found');
        }
        if ($check && !$this->teams->canChange($this->getUser(), $team)) {
            throw $this->createAccessDeniedException('Only the leads of this team can change it.');
        }

        return $team;
    }

    private function validToken(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->flashError('action.csrf.error');

        return false;
    }

    /**
     * The roles picked for a person: one or several.
     *
     * @return array<string>
     */
    private function roles(Request $request, string $field): array
    {
        $values = $request->request->all();
        $picked = $values[$field] ?? [];

        return array_map('strval', \is_array($picked) ? $picked : [$picked]);
    }

    /**
     * @param array<mixed> $values
     * @return array<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_filter(array_map('intval', $values), static fn (int $id) => $id > 0));
    }

    /**
     * A short note shown on top of the page after a change (not a pop-up: these happen often).
     */
    private function flashInfo(string $message): void
    {
        $this->requestStack->getSession()->set(self::NOTICE, $message);
    }
}
