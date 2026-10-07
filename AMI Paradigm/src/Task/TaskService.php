<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Task;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who may assign, see and change tasks, and how the tasks are loaded.
 *
 * The rules follow the organisation chart:
 * - administrators manage everyone
 * - everyone else manages the people below them in the supervisor chain
 *   (the "Supervisor" field of each person's profile); team membership plays no part
 */
final class TaskService
{
    /** @var array<int, array<int, User>> */
    private array $managedCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectRepository $projectRepository
    )
    {
    }

    public function isAdmin(User $user): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * The people this user manages (never includes the user themselves).
     *
     * @return array<int, User> keyed by user ID
     */
    public function getManagedUsers(User $user): array
    {
        $key = (int) $user->getId();
        if (isset($this->managedCache[$key])) {
            return $this->managedCache[$key];
        }

        $managed = [];

        if ($this->isAdmin($user)) {
            $all = $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.enabled = :enabled')
                ->andWhere('u.systemAccount = :system')
                ->setParameter('enabled', true)
                ->setParameter('system', false)
                ->getQuery()
                ->getResult();
            foreach ($all as $other) {
                $managed[(int) $other->getId()] = $other;
            }
        } else {
            // everyone below in the supervisor chain, level by level
            $level = [$key];
            $guard = 0;
            while ($level !== [] && $guard++ < 25) {
                $reports = $this->entityManager->createQueryBuilder()
                    ->select('u')
                    ->from(User::class, 'u')
                    ->where('u.supervisor IN (:ids)')
                    ->andWhere('u.enabled = :enabled')
                    ->setParameter('ids', $level)
                    ->setParameter('enabled', true)
                    ->getQuery()
                    ->getResult();
                $level = [];
                foreach ($reports as $report) {
                    $id = (int) $report->getId();
                    if ($id === $key || isset($managed[$id])) {
                        continue;
                    }
                    $managed[$id] = $report;
                    $level[] = $id;
                }
            }

            // Being marked as team lead of a team does NOT make someone a manager here: only the supervisor
            // chain counts. Otherwise every member flagged as "lead" would see and assign their colleagues' tasks.
        }

        unset($managed[$key]);
        uasort($managed, static fn (User $a, User $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $this->managedCache[$key] = $managed;
    }

    /**
     * Only people who manage somebody can hand out tasks.
     */
    public function canAssign(User $user): bool
    {
        return $this->isAdmin($user) || $this->getManagedUsers($user) !== [];
    }

    /**
     * The people this user may give a task to: their managed people, and themselves.
     *
     * @return array<User>
     */
    public function getAssignableUsers(User $user): array
    {
        if (!$this->canAssign($user)) {
            return [];
        }

        return array_values(array_merge([$user], $this->getManagedUsers($user)));
    }

    public function canAssignTo(User $user, User $assignee): bool
    {
        if (!$this->canAssign($user)) {
            return false;
        }

        return $assignee->getId() === $user->getId() || isset($this->getManagedUsers($user)[(int) $assignee->getId()]);
    }

    public function canView(User $user, Task $task): bool
    {
        return $this->canChangeStatus($user, $task);
    }

    /**
     * Editing and deleting: the person who assigned it, anyone who manages the assignee, or an administrator.
     */
    public function canEdit(User $user, Task $task): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($task->getCreatedBy() !== null && $task->getCreatedBy()->getId() === $user->getId()) {
            return true;
        }

        $assignee = $task->getAssignee();

        return $assignee !== null && isset($this->getManagedUsers($user)[(int) $assignee->getId()]);
    }

    /**
     * The assignee can move their own task between open, in progress and done.
     */
    public function canChangeStatus(User $user, Task $task): bool
    {
        $assignee = $task->getAssignee();
        if ($assignee !== null && $assignee->getId() === $user->getId()) {
            return true;
        }

        return $this->canEdit($user, $task);
    }

    /**
     * Tasks assigned to this user. Unfinished ones first, then by due date.
     *
     * @return array<Task>
     */
    public function getMyTasks(User $user, bool $includeDone = true): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t', 'p', 'c')
            ->from(Task::class, 't')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->where('t.assignee = :user')
            ->setParameter('user', $user);

        if (!$includeDone) {
            $qb->andWhere('t.status != :done')->setParameter('done', Task::STATUS_DONE);
        }

        return $this->sort($qb->getQuery()->getResult());
    }

    /**
     * Tasks this user handed out or oversees, not counting their own.
     *
     * @return array<Task>
     */
    public function getTeamTasks(User $user): array
    {
        if (!$this->canAssign($user)) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('t', 'p', 'c', 'a')
            ->from(Task::class, 't')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->join('t.assignee', 'a')
            ->where('t.assignee != :user')
            ->setParameter('user', $user);

        if (!$this->isAdmin($user)) {
            $qb->andWhere('t.assignee IN (:managed) OR t.createdBy = :user')
                ->setParameter('managed', array_keys($this->getManagedUsers($user)) ?: [0]);
        }

        return $this->sort($qb->getQuery()->getResult());
    }

    /**
     * Projects this user can create a task for: only the ones they have access to themselves.
     * That is the app's usual rule - projects (and customers) limited to teams are visible to
     * members of those teams only, projects without any team are open to everyone, and
     * administrators see all of them.
     *
     * @return array<Project>
     */
    public function getProjects(User $user): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(Project::class, 'p')
            ->join('p.customer', 'c')
            ->where('p.visible = :visible')
            ->andWhere('c.visible = :visible')
            ->setParameter('visible', true)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.name', 'ASC');

        // the permission filter expects the aliases "p" (project) and "c" (customer)
        $this->projectRepository->addPermissionCriteria($qb, $user);

        return $qb->getQuery()->getResult();
    }

    /**
     * Hours logged against each task, taken from the time entries that picked it.
     *
     * @param array<Task> $tasks
     * @return array<int, int> seconds, keyed by task ID
     */
    public function getLoggedSeconds(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            if ($task->getId() !== null) {
                $ids[] = (string) $task->getId();
            }
        }

        if ($ids === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.value AS task', 'COALESCE(SUM(ts.duration), 0) AS seconds')
            ->from(TimesheetMeta::class, 'm')
            ->join('m.timesheet', 'ts')
            ->where('m.name = :name')
            ->andWhere('m.value IN (:ids)')
            ->setParameter('name', Task::TIMESHEET_META_FIELD)
            ->setParameter('ids', $ids)
            ->groupBy('m.value')
            ->getQuery()
            ->getArrayResult();

        $logged = [];
        foreach ($rows as $row) {
            $logged[(int) $row['task']] = (int) $row['seconds'];
        }

        return $logged;
    }

    /**
     * Tasks somebody else assigned to this person that they have not looked at yet, for the notification bell.
     *
     * @return array<Task>
     */
    public function getUnseenTasks(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(Task::class, 't')
            ->join('t.project', 'p')
            ->where('t.assignee = :user')
            ->andWhere('t.assigneeSeen = :seen')
            ->andWhere('t.status != :done')
            ->setParameter('user', $user)
            ->setParameter('seen', false)
            ->setParameter('done', Task::STATUS_DONE)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The person opened the Tasks page: their new tasks stop ringing the bell.
     */
    public function markTasksSeen(User $user): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(Task::class, 't')
            ->set('t.assigneeSeen', ':seen')
            ->where('t.assignee = :user')
            ->andWhere('t.assigneeSeen = :unseen')
            ->setParameter('seen', true)
            ->setParameter('unseen', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Which projects each activity can be used on, following project > phase > activity:
     * an activity belongs to a project when one of the project's phases is linked to it
     * (a project without phases of its own uses the phases for all projects).
     *
     * @param array<Project> $projects
     * @return array<int, array<int>> project IDs keyed by activity ID
     */
    public function getActivityProjects(array $projects): array
    {
        $repository = $this->entityManager->getRepository(\App\Entity\Phase::class);
        $shared = $repository->findBy(['project' => null]);

        $map = [];
        foreach ($projects as $project) {
            $phases = $repository->findBy(['project' => $project]);
            if ($phases === []) {
                $phases = $shared;
            }
            foreach ($phases as $phase) {
                foreach ($phase->getActivities() as $activity) {
                    // an activity of another project cannot be used here
                    if ($activity->getProject() !== null && $activity->getProject()->getId() !== $project->getId()) {
                        continue;
                    }
                    $map[(int) $activity->getId()][(int) $project->getId()] = (int) $project->getId();
                }
            }
        }

        // activities created for one project belong to it, whether or not a phase is linked to them yet
        $own = $this->entityManager->getRepository(\App\Entity\Activity::class)->findBy(['project' => $projects]);
        foreach ($own as $activity) {
            $projectId = (int) $activity->getProject()->getId();
            $map[(int) $activity->getId()][$projectId] = $projectId;
        }

        return array_map('array_values', $map);
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
    }

    public function delete(Task $task): void
    {
        $this->entityManager->remove($task);
        $this->entityManager->flush();
    }

    public function find(int $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }

    /**
     * @param array<Task> $tasks
     * @return array<Task>
     */
    private function sort(array $tasks): array
    {
        usort($tasks, static function (Task $a, Task $b): int {
            // finished tasks go to the bottom
            if ($a->isDone() !== $b->isDone()) {
                return $a->isDone() ? 1 : -1;
            }
            // then the nearest due date first, tasks without a date last
            $dueA = $a->getDueDate()?->format('Y-m-d') ?? '9999-12-31';
            $dueB = $b->getDueDate()?->format('Y-m-d') ?? '9999-12-31';
            if ($dueA !== $dueB) {
                return $dueA <=> $dueB;
            }

            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return $tasks;
    }
}
