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

        $session = $request->getSession();
        $notice = $session->get(self::NOTICE);
        $session->remove(self::NOTICE);

        return $this->render('team-mapping/index.html.twig', [
            'page_setup' => new PageSetup('Team Mapping'),
            'notice' => \is_string($notice) ? $notice : null,
            'teams' => $teams,
            'selected' => $selected,
            'members' => $members,
            'linked' => $linked,
            'creating' => $selected === null,
            'projects' => $this->teams->getProjects(),
            'people' => $this->teams->getPeople(),
            'roles' => $this->teams->getRoles(),
            'token' => self::TOKEN,
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
            $this->flashError('A team called "' . $name . '" exists already. Choose another name.');

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
                $this->flashError('A team called "' . $name . '" exists already. Choose another name.');
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

        $lead = $request->request->getBoolean('lead');
        if (!$lead && $this->teams->isLastLead($team, $person)) {
            $lead = true;
            $this->flashWarning($person->getDisplayName() . ' stays lead: a team needs at least one lead.');
        }
        $this->teams->saveMember($team, $person, $this->roles($request, 'role'), $lead);
        $this->flashInfo($person->getDisplayName() . ' is saved in team "' . $team->getName() . '".');

        return $this->redirectToRoute('team_mapping', ['team' => $id]);
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
