<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Team;

use App\Entity\Team;
use App\Entity\User;
use App\WorkModel\WorkModelService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Teams are put together by Project Managers and Project Leads ("Team Mapping" page), not by the administrator:
 * they create the team, link its projects, add the people the project needs and say what each one does
 * there (Frontend Developer, Tester, AI Engineer ...). The administrator only creates customers, projects
 * and users, and gives each user their role in the system (Employee, Project Lead, Project Manager).
 *
 * The person who creates a team is its lead; every lead of a team can change it.
 * Writes go straight to the tables, so nothing else in the team is touched.
 */
final class TeamMappingService
{
    /** Offered for "Role in team"; anything typed in that is not here is added to the list */
    public const DEFAULT_ROLES = [
        'Project Manager',
        'Project Lead',
        'Solution Architect',
        'Business Analyst',
        'UI/UX Designer',
        'Frontend Developer',
        'Backend Developer',
        'Full Stack Developer',
        'Mobile App Developer',
        'AI Engineer',
        'Data Engineer',
        'DevOps Engineer',
        'Tester / QA',
        'Technical Writer',
    ];

    private const COLORS = ['#206bc4', '#2fb344', '#d63939', '#f76707', '#ae3ec9', '#0ca678', '#4263eb', '#f59f00'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The teams this person can change: those they lead. A system administrator sees every team,
     * so a team whose leads have left can still be looked after.
     *
     * @return array<Team>
     */
    public function getTeams(User $user): array
    {
        $teams = $this->entityManager->getRepository(Team::class)->findBy([], ['name' => 'ASC']);
        if ($user->isSuperAdmin()) {
            return $teams;
        }

        return array_values(array_filter($teams, fn (Team $team) => $this->isLead($team, $user)));
    }

    public function canChange(User $user, Team $team): bool
    {
        return $user->isSuperAdmin() || $this->isLead($team, $user);
    }

    /**
     * Compared by ID: the logged-in user and the team's members may be different objects of the same person.
     */
    public function isLead(Team $team, User $user): bool
    {
        foreach ($team->getMembers() as $member) {
            if ($member->isTeamlead() && $member->getUser()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roles to pick from: the ready list plus every role already given to somebody.
     *
     * @return array<string>
     */
    public function getRoles(): array
    {
        $used = $this->connection()->fetchFirstColumn('SELECT DISTINCT team_role FROM kimai2_users_teams WHERE team_role IS NOT NULL AND team_role <> \'\'');
        $roles = self::DEFAULT_ROLES;
        // a person can have several roles, kept as "Frontend Developer, Tester / QA"
        foreach ($used as $value) {
            foreach (explode(',', (string) $value) as $role) {
                $role = trim($role);
                if ($role !== '' && !\in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        }

        return $roles;
    }

    /**
     * Everybody who can be added to a team: all active people.
     *
     * @return array<User>
     */
    public function getPeople(): array
    {
        $people = $this->entityManager->getRepository(User::class)->findBy(['enabled' => true]);
        usort($people, static fn (User $a, User $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $people;
    }

    /**
     * Projects a team can be linked to. The two built-in projects are left out: everybody books on those,
     * and a project linked to a team is only offered to the people of that team.
     *
     * @return array<int, string> project name by ID
     */
    public function getProjects(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, name FROM kimai2_projects WHERE visible = 1 AND name NOT IN (?, ?) ORDER BY name',
            [\App\Entity\Phase::NON_PROJECT_NAME, WorkModelService::PRESALES_PROJECT]
        );
        $projects = [];
        foreach ($rows as $row) {
            $projects[(int) $row['id']] = (string) $row['name'];
        }

        return $projects;
    }

    public function nameTaken(string $name, ?int $exceptTeamId = null): bool
    {
        $id = $this->connection()->fetchOne('SELECT id FROM kimai2_teams WHERE LOWER(name) = LOWER(?)', [trim($name)]);

        return $id !== false && (int) $id !== $exceptTeamId;
    }

    /**
     * Creates a team; the person creating it becomes its lead, with the given role.
     *
     * @param array<int> $projectIds
     */
    public function createTeam(User $creator, string $name, array $projectIds, array|string|null $myRole): int
    {
        $db = $this->connection();
        $db->beginTransaction();
        try {
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_teams');
            $db->insert('kimai2_teams', ['name' => trim($name), 'color' => self::COLORS[$count % \count(self::COLORS)]]);
            $teamId = (int) $db->lastInsertId();
            $db->insert('kimai2_users_teams', ['user_id' => $creator->getId(), 'team_id' => $teamId, 'teamlead' => 1, 'team_role' => $this->clean($myRole)]);
            $this->writeProjects($teamId, $projectIds);
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
        $this->entityManager->clear();

        return $teamId;
    }

    /**
     * @param array<int> $projectIds
     */
    public function updateTeam(Team $team, string $name, array $projectIds): void
    {
        $db = $this->connection();
        $db->beginTransaction();
        try {
            $db->update('kimai2_teams', ['name' => trim($name)], ['id' => $team->getId()]);
            $this->writeProjects((int) $team->getId(), $projectIds);
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
        $this->entityManager->clear();
    }

    public function deleteTeam(Team $team): void
    {
        // memberships and project links go with it (foreign keys)
        $this->connection()->delete('kimai2_teams', ['id' => $team->getId()]);
        $this->entityManager->clear();
    }

    /**
     * Adds a person (or changes them when they are in the team already).
     */
    public function saveMember(Team $team, User $person, array|string|null $role, bool $lead): void
    {
        $db = $this->connection();
        $exists = $db->fetchOne('SELECT id FROM kimai2_users_teams WHERE team_id = ? AND user_id = ?', [$team->getId(), $person->getId()]);
        $values = ['team_role' => $this->clean($role), 'teamlead' => $lead ? 1 : 0];
        if ($exists !== false) {
            $db->update('kimai2_users_teams', $values, ['id' => (int) $exists]);
        } else {
            $db->insert('kimai2_users_teams', $values + ['team_id' => $team->getId(), 'user_id' => $person->getId()]);
        }
        $this->entityManager->clear();
    }

    public function removeMember(Team $team, User $person): void
    {
        $this->connection()->delete('kimai2_users_teams', ['team_id' => $team->getId(), 'user_id' => $person->getId()]);
        $this->entityManager->clear();
    }

    /**
     * True when this person is the team's only lead: then they cannot leave or stop being lead,
     * otherwise nobody could change the team any more.
     */
    public function isLastLead(Team $team, User $person): bool
    {
        $leads = $team->getTeamleads();

        return \count($leads) === 1 && $leads[0]->getId() === $person->getId();
    }

    /**
     * @param array<int> $projectIds
     */
    private function writeProjects(int $teamId, array $projectIds): void
    {
        $db = $this->connection();
        $allowed = $this->getProjects();
        // only the projects this page offers are changed; links to other projects stay as they are
        if ($allowed !== []) {
            $db->executeStatement(
                'DELETE FROM kimai2_projects_teams WHERE team_id = ? AND project_id IN (?)',
                [$teamId, array_keys($allowed)],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        }
        foreach (array_unique($projectIds) as $projectId) {
            if (isset($allowed[(int) $projectId])) {
                $db->insert('kimai2_projects_teams', ['project_id' => (int) $projectId, 'team_id' => $teamId]);
            }
        }
    }

    /**
     * One role, or several (a list, or text separated by commas), stored as "Frontend Developer, Tester / QA".
     *
     * @param array<mixed>|string|null $roles
     */
    private function clean(array|string|null $roles): ?string
    {
        if (!\is_array($roles)) {
            $roles = explode(',', (string) $roles);
        }
        $clean = [];
        foreach ($roles as $role) {
            // a comma separates roles, so it cannot be part of one
            $role = trim(preg_replace('/\s+/u', ' ', str_replace(',', ' ', (string) $role)) ?? '');
            if ($role !== '' && !\in_array(mb_strtolower($role), array_map('mb_strtolower', $clean), true)) {
                $clean[] = mb_substr($role, 0, 80);
            }
        }
        $text = implode(', ', $clean);

        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
