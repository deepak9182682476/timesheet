<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\WorkModel;

use App\Entity\Activity;
use App\Entity\Phase;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Entity\Task;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Entity\WorkItem;
use App\Form\Model\MultiUserTimesheet;
use App\Task\TaskService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Project models and the items people book their time on.
 *
 * Every project follows one model, which decides what is picked on a time entry after the project:
 * - Agile:      Epic > Feature > User Story > Activity > Task
 * - Waterfall:  Module > Sub Module > Business Req > Activity > Task
 * - Pre-sales:  Lead > Phase > Activity > Task (one built-in project called "Pre-Sales")
 * - "Non-Project Activities" keeps the older Phase > Activity > Task form.
 *
 * An administrator creates the project and sets its model (Agile unless changed). Managers and leads
 * create the items below it and assign them to their people; a person only sees the items assigned to them.
 */
final class WorkModelService
{
    public const AGILE = 'agile';
    public const WATERFALL = 'waterfall';
    public const PRESALES = 'presales';
    public const NON_PROJECT = 'nonproject';

    /** Name of the custom field of a project that holds its model */
    public const PROJECT_META = 'model';
    /** Name of the built-in project all pre-sales work is booked on */
    public const PRESALES_PROJECT = 'Pre-Sales';

    /** The models an administrator can pick for a project */
    public const PROJECT_MODELS = [
        self::AGILE => 'Agile',
        self::WATERFALL => 'Waterfall',
    ];

    /** What the levels of each model are called, top level first. The last two are always Activity and Task. */
    public const LEVELS = [
        self::AGILE => ['Epic', 'Feature', 'User Story', 'Activity', 'Task'],
        self::WATERFALL => ['Module', 'Sub Module', 'Business Req', 'Activity', 'Task'],
        self::PRESALES => ['Lead', 'Category', 'Activity', 'Task'],
    ];

    /** Custom field of a time entry: the number of the picked item (its Task, or its Activity when no task was picked) */
    public const META_ITEM = 'work_item';
    /** "Weekly hours" lists every item of a person in each row, up to this many (see getBookableItems) */
    public const GRID_ITEMS = 300;
    /** Custom fields of a time entry holding the names of the picked levels, for the lists and exports */
    public const META_LEVELS = [
        'wi_l1' => 'Epic / Module / Lead',
        'wi_l2' => 'Feature / Sub Module',
        'wi_l3' => 'User Story / Business Req',
    ];

    /** @var array<int, string>|null */
    private ?array $projectModels = null;
    /** @var array<int, array<string, Activity>> the activities of each project by lower-case name */
    private array $activities = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskService $tasks
    )
    {
    }

    // ------------------------------------------------------------------ models

    public function getModel(Project $project): string
    {
        if ($project->getName() === Phase::NON_PROJECT_NAME) {
            return self::NON_PROJECT;
        }
        if ($project->getName() === self::PRESALES_PROJECT) {
            return self::PRESALES;
        }

        $meta = $project->getMetaField(self::PROJECT_META);
        $value = $meta !== null ? (string) $meta->getValue() : '';

        // a project nobody picked a model for is Agile
        return isset(self::PROJECT_MODELS[$value]) ? $value : self::AGILE;
    }

    public function usesItems(Project $project): bool
    {
        return $this->getModel($project) !== self::NON_PROJECT;
    }

    public function getModelName(Project $project): string
    {
        $model = $this->getModel($project);

        return match ($model) {
            self::PRESALES => 'Pre-Sales',
            self::NON_PROJECT => 'Non-Project',
            default => self::PROJECT_MODELS[$model],
        };
    }

    /**
     * @return array<int, string> names of the levels of this project, top level first
     */
    public function getLevels(Project $project): array
    {
        return self::LEVELS[$this->getModel($project)] ?? [];
    }

    /** Position of "Activity" among the levels of this project */
    public function getActivityLevel(Project $project): int
    {
        return \count($this->getLevels($project)) - 2;
    }

    public function getLevelName(WorkItem $item): string
    {
        $levels = $item->getProject() !== null ? $this->getLevels($item->getProject()) : [];

        return $levels[$item->getLevel()] ?? 'Item';
    }

    /**
     * The model of every project, keyed by project ID.
     *
     * @return array<int, string>
     */
    public function getProjectModels(): array
    {
        if ($this->projectModels !== null) {
            return $this->projectModels;
        }

        $models = [];
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id', 'p.name')
            ->from(Project::class, 'p')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $models[(int) $row['id']] = match ($row['name']) {
                Phase::NON_PROJECT_NAME => self::NON_PROJECT,
                self::PRESALES_PROJECT => self::PRESALES,
                default => self::AGILE,
            };
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.project) AS project', 'm.value')
            ->from(ProjectMeta::class, 'm')
            ->where('m.name = :name')
            ->setParameter('name', self::PROJECT_META)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $id = (int) $row['project'];
            if (isset($models[$id]) && \in_array($models[$id], [self::AGILE, self::WATERFALL], true) && isset(self::PROJECT_MODELS[(string) $row['value']])) {
                $models[$id] = (string) $row['value'];
            }
        }

        return $this->projectModels = $models;
    }

    /**
     * The kinds of work (agile, waterfall, presales, nonproject) that have time entries: those of one person,
     * or of everybody when no person is given. In the order the lists show them.
     *
     * @return array<int, string>
     */
    public function getModelsInUse(?User $user): array
    {
        $connection = $this->entityManager->getConnection();
        $projectIds = $user !== null
            ? $connection->fetchFirstColumn('SELECT DISTINCT project_id FROM kimai2_timesheet WHERE `user` = ?', [(int) $user->getId()])
            : $connection->fetchFirstColumn('SELECT DISTINCT project_id FROM kimai2_timesheet');

        $models = $this->getProjectModels();
        $used = [];
        foreach ($projectIds as $projectId) {
            if (isset($models[(int) $projectId])) {
                $used[$models[(int) $projectId]] = true;
            }
        }

        return array_values(array_filter([self::AGILE, self::WATERFALL, self::PRESALES, self::NON_PROJECT], static fn (string $model) => isset($used[$model])));
    }

    /**
     * How an export of these entries is laid out: the columns follow the kind of work the entries are.
     * - all of one kind: one column per level, named the way that model names it (Epic, Feature, User Story ...)
     * - mixed: the general columns (Epic / Module / Lead ...) and the Phase
     * The project column is left out where it says nothing (Pre-sales, Non-project), the employee
     * column when all entries are of one person.
     *
     * @param iterable<mixed> $entries time entries (anything with getProject() and getUser())
     * @return array<string, mixed>
     */
    public function describeExport(iterable $entries): array
    {
        $models = $this->getProjectModels();
        // Agile and Waterfall are both shown as "Project" (earlier: 'Agile', 'Waterfall')
        $names = [self::AGILE => 'Project', self::WATERFALL => 'Project', self::PRESALES => 'Pre-Sales', self::NON_PROJECT => 'Non-Project'];

        $kinds = [];
        $users = [];
        $types = [];
        foreach ($entries as $entry) {
            if (!\is_object($entry) || !method_exists($entry, 'getProject') || !method_exists($entry, 'getUser')) {
                continue;
            }
            $projectId = (int) $entry->getProject()?->getId();
            $kind = $models[$projectId] ?? self::AGILE;
            $kinds[$kind] = true;
            $types[$projectId] = $names[$kind];
            $users[(int) $entry->getUser()?->getId()] = true;
        }

        $levelFields = array_keys(self::META_LEVELS);
        $model = \count($kinds) === 1 ? (string) array_key_first($kinds) : 'mixed';
        // Agile and Waterfall together are just "projects": their levels side by side, no Phase column
        if ($model === 'mixed' && array_diff(array_keys($kinds), [self::AGILE, self::WATERFALL]) === []) {
            $model = 'project';
        }
        $columns = match ($model) {
            self::AGILE, self::WATERFALL => array_combine($levelFields, \array_slice(self::LEVELS[$model], 0, 3)),
            'project' => [$levelFields[0] => 'Epic / Module', $levelFields[1] => 'Feature / Sub Module', $levelFields[2] => 'User Story / Business Req'],
            self::PRESALES => [$levelFields[0] => 'Lead', Phase::TIMESHEET_META_FIELD => 'Category'],
            self::NON_PROJECT => [Phase::TIMESHEET_META_FIELD => 'Category'],
            default => array_merge(self::META_LEVELS, [Phase::TIMESHEET_META_FIELD => 'Category']),
        };

        return [
            'model' => $model,
            'title' => match ($model) {
                'mixed' => 'All work',
                'project', self::AGILE, self::WATERFALL => 'Projects',
                self::NON_PROJECT => 'Non-Project Activities',
                default => $names[$model],
            },
            'mixed' => $model === 'mixed',
            'columns' => $columns,
            'showProject' => !\in_array($model, [self::PRESALES, self::NON_PROJECT], true),
            'showEmployee' => \count($users) > 1,
            // for mixed exports: what kind of work each project is, by project ID
            'types' => $types,
        ];
    }

    public function getNonProject(): ?Project
    {
        return $this->entityManager->getRepository(Project::class)->findOneBy(['name' => Phase::NON_PROJECT_NAME]);
    }

    /**
     * The built-in project for pre-sales work. It is created the first time it is needed,
     * next to "Non-Project Activities".
     */
    public function getPresalesProject(bool $create = true): ?Project
    {
        $repository = $this->entityManager->getRepository(Project::class);
        $project = $repository->findOneBy(['name' => self::PRESALES_PROJECT]);
        if ($project !== null || !$create) {
            return $project;
        }

        $customer = $this->getNonProject()?->getCustomer();
        if ($customer === null) {
            return null;
        }

        $project = new Project();
        $project->setName(self::PRESALES_PROJECT);
        $project->setCustomer($customer);
        $project->setComment('Pre-sales work: Lead > Category > Activity > Task. Managers and leads maintain the leads on the "Project mapping" page.');
        $project->setGlobalActivities(false);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $this->projectModels = null;

        return $project;
    }

    // ------------------------------------------------------------------ who may do what

    /**
     * Managers and leads (everybody with people below them) and administrators map the items.
     */
    public function canManage(User $user): bool
    {
        return $this->tasks->canAssign($user);
    }

    /**
     * The projects this person can map items for: the ones they have access to, and Pre-sales.
     *
     * @return array<Project>
     */
    public function getManageableProjects(User $user): array
    {
        if (!$this->canManage($user)) {
            return [];
        }

        // the projects first, Pre-sales last
        $projects = [];
        $presales = $this->getPresalesProject();
        foreach ($this->tasks->getProjects($user) as $project) {
            if ($this->usesItems($project) && $project->getId() !== $presales?->getId()) {
                $projects[(int) $project->getId()] = $project;
            }
        }
        if ($presales !== null) {
            $projects[(int) $presales->getId()] = $presales;
        }

        return array_values($projects);
    }

    public function canManageProject(User $user, Project $project): bool
    {
        foreach ($this->getManageableProjects($user) as $candidate) {
            if ($candidate->getId() === $project->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The people this person can assign an item to: themselves and everybody below them.
     *
     * @return array<User>
     */
    public function getAssignableUsers(User $user): array
    {
        return $this->tasks->getAssignableUsers($user);
    }

    // ------------------------------------------------------------------ items

    public function find(int $id): ?WorkItem
    {
        return $this->entityManager->find(WorkItem::class, $id);
    }

    /**
     * All items of a project in the order of the tree: every item is followed by the items below it.
     *
     * @return array<WorkItem>
     */
    public function getTree(Project $project): array
    {
        $items = $this->entityManager->getRepository(WorkItem::class)->findBy(['project' => $project], ['position' => 'ASC', 'name' => 'ASC']);

        $children = [];
        foreach ($items as $item) {
            $children[$item->getParent()?->getId() ?? 0][] = $item;
        }

        $sorted = [];
        $walk = static function (int $parent) use (&$walk, &$sorted, $children): void {
            foreach ($children[$parent] ?? [] as $item) {
                $sorted[] = $item;
                $walk((int) $item->getId());
            }
        };
        $walk(0);

        return $sorted;
    }

    /**
     * How two names are compared: "Check out", "check  out" and " Check out " are the same item.
     * Used by the form and the Excel upload, so neither adds an item that is there already.
     */
    public static function nameKey(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''));
    }

    /**
     * Is there already an item with this name at the same place?
     */
    public function nameExists(WorkItem $item): bool
    {
        $siblings = $this->entityManager->getRepository(WorkItem::class)->findBy(['project' => $item->getProject(), 'parent' => $item->getParent()]);
        foreach ($siblings as $sibling) {
            if ($sibling->getId() !== $item->getId() && self::nameKey($sibling->getName()) === self::nameKey($item->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * One level of the tree: the items directly below the given item (or the top level of the project).
     *
     * @return array<WorkItem>
     */
    public function getChildren(Project $project, ?WorkItem $parent): array
    {
        return $this->entityManager->getRepository(WorkItem::class)->findBy(['project' => $project, 'parent' => $parent], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * How many items sit directly below each of the given items.
     *
     * @param array<WorkItem> $items
     * @return array<int, int> keyed by item ID
     */
    public function countChildren(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) $item->getId();
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.parent) AS parent', 'COUNT(i.id) AS amount')
            ->from(WorkItem::class, 'i')
            ->where('i.parent IN (:ids)')
            ->setParameter('ids', $ids)
            ->groupBy('i.parent')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['parent']] = (int) $row['amount'];
        }

        return $counts;
    }

    /**
     * The people who belong to each project:
     * - everybody assigned to any of its items,
     * - the managers and leads who built its mapping (created its items),
     * - everybody who logged time on it.
     * A project that is missing here has no mapping at all, for example "Non-Project Activities".
     *
     * @return array<int, array<int>> user IDs keyed by project ID
     */
    public function getMappedUserIds(): array
    {
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT DISTINCT i.project_id, u.user_id FROM kimai2_work_item_users u JOIN kimai2_work_items i ON i.id = u.work_item_id'
        );
        $rows = array_merge($rows, $connection->fetchAllAssociative(
            'SELECT DISTINCT project_id, created_by_id AS user_id FROM kimai2_work_items WHERE created_by_id IS NOT NULL'
        ));

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int) $row['project_id']][(int) $row['user_id']] = (int) $row['user_id'];
        }
        if ($mapped === []) {
            return [];
        }

        // people who logged time count as well, but only for projects that have a mapping
        $logged = $connection->fetchAllAssociative(
            'SELECT DISTINCT project_id, `user` AS user_id FROM kimai2_timesheet WHERE project_id IN (' . implode(',', array_keys($mapped)) . ')'
        );
        foreach ($logged as $row) {
            $mapped[(int) $row['project_id']][(int) $row['user_id']] = (int) $row['user_id'];
        }

        return array_map('array_values', $mapped);
    }

    /**
     * For items that have nobody of their own (and nobody from above): the people assigned somewhere below them.
     * Those people do see the item on a time entry, on the way to what is theirs.
     *
     * @param array<WorkItem> $items
     * @return array<int, array<int, User>> people keyed by item ID, then by user ID
     */
    public function getPeopleBelow(Project $project, array $items): array
    {
        $wanted = [];
        foreach ($items as $item) {
            if ($item->getEffectiveUsers() === []) {
                $wanted[(int) $item->getId()] = [];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $projectId = (int) $project->getId();
        $parents = [];
        foreach ($connection->fetchAllAssociative('SELECT id, parent_id FROM kimai2_work_items WHERE project_id = ?', [$projectId]) as $row) {
            $parents[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }
        $pairs = $connection->fetchAllAssociative(
            'SELECT u.work_item_id, u.user_id FROM kimai2_work_item_users u JOIN kimai2_work_items i ON i.id = u.work_item_id WHERE i.project_id = ?',
            [$projectId]
        );

        $userIds = [];
        foreach ($pairs as $pair) {
            $step = $parents[(int) $pair['work_item_id']] ?? null;
            $guard = 0;
            while ($step !== null && $guard++ < 20) {
                if (isset($wanted[$step])) {
                    $wanted[$step][(int) $pair['user_id']] = true;
                    $userIds[(int) $pair['user_id']] = true;
                }
                $step = $parents[$step] ?? null;
            }
        }
        if ($userIds === []) {
            return [];
        }

        $users = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['id' => array_keys($userIds)]) as $user) {
            $users[(int) $user->getId()] = $user;
        }

        $people = [];
        foreach ($wanted as $itemId => $ids) {
            foreach (array_keys($ids) as $userId) {
                if (isset($users[$userId])) {
                    $people[$itemId][$userId] = $users[$userId];
                }
            }
        }

        return $people;
    }

    public function countItems(Project $project): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(WorkItem::class, 'i')
            ->where('i.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Finds items of a project by a part of their name, by a person they are assigned to directly, or both.
     *
     * @return array<WorkItem>
     */
    public function search(Project $project, string $term, ?User $person, int $limit = 200): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(WorkItem::class, 'i')
            ->where('i.project = :project')
            ->setParameter('project', $project)
            ->orderBy('i.level', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->setMaxResults($limit);

        if ($term !== '') {
            $qb->andWhere('i.name LIKE :term')->setParameter('term', '%' . addcslashes($term, '%_\\') . '%');
        }
        if ($person !== null) {
            $qb->andWhere(':person MEMBER OF i.users')->setParameter('person', $person);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Changes who a number of items are assigned to in one go.
     *
     * @param array<WorkItem> $items
     * @param array<User> $users
     * @param string $mode "add" puts the people on the items, "remove" takes them off, "replace" leaves exactly these
     *                     people, "clear" takes everybody off (the item is then for the people of the item above)
     * @return int the number of items that changed
     */
    public function assign(array $items, array $users, string $mode): int
    {
        $changed = 0;
        foreach ($items as $item) {
            $before = [];
            foreach ($item->getUsers() as $user) {
                $before[(int) $user->getId()] = $user;
            }
            $after = ($mode === 'replace' || $mode === 'clear') ? [] : $before;
            foreach ($users as $user) {
                if ($mode === 'remove') {
                    unset($after[(int) $user->getId()]);
                } elseif ($mode !== 'clear') {
                    $after[(int) $user->getId()] = $user;
                }
            }
            if (array_diff_key($before, $after) === [] && array_diff_key($after, $before) === []) {
                continue;
            }
            foreach ($before as $id => $user) {
                if (!isset($after[$id])) {
                    $item->removeUser($user);
                }
            }
            foreach ($after as $id => $user) {
                if (!isset($before[$id])) {
                    $item->addUser($user);
                }
            }
            $changed++;
        }
        $this->entityManager->flush();

        return $changed;
    }

    /**
     * Gets an item ready to be stored: an item on the "Activity" level comes with the activity
     * the time entries will carry. The caller stores it (see save(), and the Excel upload).
     */
    public function prepare(WorkItem $item): void
    {
        $project = $item->getProject();
        if ($project !== null && $item->getLevel() === $this->getActivityLevel($project)) {
            $item->setActivity($this->findOrCreateActivity($project, (string) $item->getName()));
        } else {
            $item->setActivity(null);
        }

        $this->entityManager->persist($item);
    }

    public function save(WorkItem $item): void
    {
        $this->prepare($item);
        $this->entityManager->flush();
    }

    public function delete(WorkItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /**
     * The activity with this name in this project; it is created when the project has none yet.
     */
    private function findOrCreateActivity(Project $project, string $name): Activity
    {
        $projectId = (int) $project->getId();
        if (!isset($this->activities[$projectId])) {
            $this->activities[$projectId] = [];
            foreach ($this->entityManager->getRepository(Activity::class)->findBy(['project' => $project]) as $activity) {
                $this->activities[$projectId][mb_strtolower((string) $activity->getName())] = $activity;
            }
        }

        $key = mb_strtolower($name);
        if (isset($this->activities[$projectId][$key])) {
            $activity = $this->activities[$projectId][$key];
            if (!$activity->isVisible()) {
                $activity->setVisible(true);
            }

            return $activity;
        }

        $activity = new Activity();
        $activity->setName($name);
        $activity->setProject($project);
        $this->entityManager->persist($activity);

        return $this->activities[$projectId][$key] = $activity;
    }

    // ------------------------------------------------------------------ time entries

    /**
     * Everything the time entry form needs to offer the right items (see partials/work-item-cascade.html.twig).
     *
     * A person filling in their own entry gets their own items only. A form in which the person can be
     * chosen (entries made for the team) also gets the items of the projects the viewer can map.
     * Plain rows are read instead of objects: a project can have many thousands of items.
     *
     * @return array<string, mixed>
     */
    public function getFormData(User $viewer, Timesheet $timesheet, bool $forOthers = false): array
    {
        $owner = $timesheet->getUser() ?? $viewer;
        $ownerId = (int) $owner->getId();

        $manageable = [];
        if ($forOthers) {
            foreach ($this->getManageableProjects($viewer) as $project) {
                $manageable[(int) $project->getId()] = true;
            }
        }

        $current = $timesheet->getMetaField(self::META_ITEM);
        $currentValue = $current !== null ? (string) $current->getValue() : '';

        try {
            // only the projects that matter here: where the person has something, what the entry uses, what the viewer maps
            $projectIds = array_merge($this->getAssignedProjectIds($ownerId), array_keys($manageable));
            if ($timesheet->getProject() !== null) {
                $projectIds[] = (int) $timesheet->getProject()->getId();
            }
            $rows = $this->loadRows($projectIds);
        } catch (\Exception) {
            // the tables are created by a migration: without them there is simply nothing to pick
            $rows = [];
        }

        // what the entry already uses stays in the lists, whoever it is assigned to now
        $keep = ctype_digit($currentValue) ? [(int) $currentValue => true] : [];
        $mine = $this->visibleRows($rows, $ownerId, $keep);

        $items = [];
        foreach ($rows as $id => $row) {
            if (!isset($mine[$id]) && !isset($manageable[$row['project']])) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'project' => $row['project'],
                'parent' => $row['parent'],
                'level' => $row['level'],
                'name' => $row['name'],
                'activity' => $row['activity'],
                'users' => $row['effective'],
            ];
        }

        $project = $timesheet->getProject();

        return [
            'user' => $ownerId,
            'nonproject' => $this->getNonProject()?->getId(),
            'presales' => $this->getPresalesProject()?->getId(),
            'models' => $this->getProjectModels(),
            'levels' => self::LEVELS,
            'items' => $items,
            'current' => $currentValue !== '' ? (int) $currentValue : null,
            // an entry from before the models existed keeps its old fields until somebody picks an item for it
            'legacy' => $timesheet->getId() !== null && $project !== null && $this->usesItems($project) && $currentValue === '',
        ];
    }

    /**
     * Every item as a plain row, with the people who can book on it ("effective": its own people,
     * or else the people of the nearest item above that has any).
     *
     * @param array<int> $projectIds the projects to read
     * @return array<int, array{project: int, parent: int|null, level: int, name: string, activity: int|null, users: array<int>, effective: array<int>}>
     */
    private function loadRows(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
        if ($projectIds === []) {
            return [];
        }

        $rows = [];
        $result = $this->entityManager->createQueryBuilder()
            ->select('i.id', 'IDENTITY(i.project) AS project', 'IDENTITY(i.parent) AS parent', 'i.level', 'i.name', 'IDENTITY(i.activity) AS activity')
            ->from(WorkItem::class, 'i')
            ->where('i.project IN (:projects)')
            ->setParameter('projects', $projectIds)
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
        foreach ($result as $row) {
            $rows[(int) $row['id']] = [
                'project' => (int) $row['project'],
                'parent' => $row['parent'] !== null ? (int) $row['parent'] : null,
                'level' => (int) $row['level'],
                'name' => (string) $row['name'],
                'activity' => $row['activity'] !== null ? (int) $row['activity'] : null,
                'users' => [],
                'effective' => [],
            ];
        }

        $pairs = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT u.work_item_id, u.user_id FROM kimai2_work_item_users u JOIN kimai2_work_items i ON i.id = u.work_item_id WHERE i.project_id IN (' . implode(',', $projectIds) . ')'
        );
        foreach ($pairs as $pair) {
            $id = (int) $pair['work_item_id'];
            if (isset($rows[$id])) {
                $rows[$id]['users'][] = (int) $pair['user_id'];
            }
        }

        foreach ($rows as $id => $row) {
            $step = $id;
            $guard = 0;
            while ($step !== null && isset($rows[$step]) && $guard++ < 20) {
                if ($rows[$step]['users'] !== []) {
                    $rows[$id]['effective'] = $rows[$step]['users'];
                    break;
                }
                $step = $rows[$step]['parent'];
            }
        }

        return $rows;
    }

    /**
     * The projects in which something is assigned to this person directly.
     *
     * @return array<int>
     */
    private function getAssignedProjectIds(int $userId): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT i.project_id FROM kimai2_work_item_users u JOIN kimai2_work_items i ON i.id = u.work_item_id WHERE u.user_id = ?',
            [$userId]
        );

        return array_map('intval', $ids);
    }

    /**
     * The rows a person can see: the ones they can book on and the items above those.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, true> $keep IDs that count as visible whoever they are assigned to
     * @return array<int, true>
     */
    private function visibleRows(array $rows, int $userId, array $keep = []): array
    {
        $visible = [];
        foreach ($rows as $id => $row) {
            if (!isset($keep[$id]) && !\in_array($userId, $row['effective'], true)) {
                continue;
            }
            $step = $id;
            $guard = 0;
            while ($step !== null && isset($rows[$step]) && !isset($visible[$step]) && $guard++ < 20) {
                $visible[$step] = true;
                $step = $rows[$step]['parent'];
            }
        }

        return $visible;
    }

    /**
     * What a person can book time on, for the "Weekly hours" grid: every activity and task assigned to them,
     * and whatever their entries already use (so an entry never loses its item just because it was reassigned).
     *
     * @return array<int, array{id: int, label: string, project: int, activity: int|null, activityName: string}>
     */
    public function getBookableItems(User $user): array
    {
        $userId = (int) $user->getId();

        $used = [];
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT m.value')
            ->from(TimesheetMeta::class, 'm')
            ->join('m.timesheet', 't')
            ->where('m.name = :name')
            ->andWhere('t.user = :user')
            ->andWhere('m.value IS NOT NULL')
            ->setParameter('name', self::META_ITEM)
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();
        foreach ($result as $row) {
            $used[(int) $row['value']] = true;
        }

        $projectIds = $this->getAssignedProjectIds($userId);
        $usedIds = array_keys($used);
        if ($usedIds !== []) {
            $projectIds = array_merge($projectIds, $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT project_id FROM kimai2_work_items WHERE id IN (' . implode(',', array_map('intval', $usedIds)) . ')'
            ));
        }
        $rows = $this->loadRows($projectIds);
        $models = $this->getProjectModels();

        $picked = [];
        $activityIds = [];
        $candidates = [];
        foreach ($rows as $id => $row) {
            $levels = self::LEVELS[$models[$row['project']] ?? ''] ?? null;
            if ($levels === null) {
                continue;
            }
            $activityLevel = \count($levels) - 2;
            if ($row['level'] < $activityLevel || (!isset($used[$id]) && !\in_array($userId, $row['effective'], true))) {
                continue;
            }
            $candidates[$id] = true;
        }

        // The grid holds this list once per row. With a long list only what the person has booked on before is
        // offered there; a first booking on another item is made with the time entry form.
        if (\count($candidates) > self::GRID_ITEMS) {
            $candidates = array_intersect_key($candidates, $used);
        }

        foreach (array_keys($candidates) as $id) {
            $row = $rows[$id];
            $activityLevel = \count(self::LEVELS[$models[$row['project']]]) - 2;

            $names = [];
            $activity = null;
            $step = $id;
            $guard = 0;
            while ($step !== null && isset($rows[$step]) && $guard++ < 20) {
                array_unshift($names, $rows[$step]['name']);
                if ($rows[$step]['level'] === $activityLevel) {
                    $activity = $rows[$step]['activity'];
                }
                $step = $rows[$step]['parent'];
            }
            if ($activity !== null) {
                $activityIds[$activity] = true;
            }
            $picked[] = ['id' => $id, 'label' => implode(' › ', $names), 'project' => $row['project'], 'activity' => $activity, 'activityName' => ''];
        }

        $activityNames = [];
        if ($activityIds !== []) {
            $result = $this->entityManager->createQueryBuilder()
                ->select('a.id', 'a.name')
                ->from(Activity::class, 'a')
                ->where('a.id IN (:ids)')
                ->setParameter('ids', array_keys($activityIds))
                ->getQuery()
                ->getArrayResult();
            foreach ($result as $row) {
                $activityNames[(int) $row['id']] = (string) $row['name'];
            }
        }
        foreach ($picked as $index => $item) {
            $picked[$index]['activityName'] = $item['activity'] !== null ? ($activityNames[$item['activity']] ?? '') : '';
        }

        usort($picked, static fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $picked;
    }

    /**
     * Checks the item picked on a time entry. Returns what is wrong, or null when all is fine.
     */
    public function check(Timesheet $timesheet): ?string
    {
        $project = $timesheet->getProject();
        if ($project === null || !$this->usesItems($project)) {
            return null;
        }

        $levels = $this->getLevels($project);
        $wanted = implode(', ', \array_slice($levels, 0, -1));

        $meta = $timesheet->getMetaField(self::META_ITEM);
        $value = $meta !== null ? (string) $meta->getValue() : '';
        if ($value === '') {
            // entries from before the models existed can still be corrected without picking an item
            if ($timesheet->getId() !== null) {
                return null;
            }

            return 'Please pick the item for this project (' . $wanted . ').';
        }

        $item = ctype_digit($value) ? $this->find((int) $value) : null;
        if ($item === null) {
            return 'The picked item does not exist any more. Please pick ' . $wanted . ' again.';
        }
        if ($item->getProject()?->getId() !== $project->getId()) {
            return 'The picked ' . $this->getLevelName($item) . ' belongs to another project. Please pick ' . $wanted . ' again.';
        }
        if ($item->getLevel() < $this->getActivityLevel($project)) {
            return 'Please pick ' . $wanted . ' for this project.';
        }

        // who it is assigned to is checked when the entry is made; later corrections of the entry stay possible.
        // An entry for several people at once is checked person by person, on the copies made for them.
        $user = $timesheet->getUser();
        if ($timesheet->getId() === null && !($timesheet instanceof MultiUserTimesheet) && $user !== null && !isset($item->getEffectiveUsers()[(int) $user->getId()])) {
            // only tasks below this activity are assigned to the person: the task has to be picked as well
            if ($item->getLevel() === $this->getActivityLevel($project) && isset($this->getPeopleBelow($project, [$item])[(int) $item->getId()][(int) $user->getId()])) {
                return \sprintf('Please pick the %s as well: on %s "%s" only single tasks are assigned to %s.', end($levels), $this->getLevelName($item), $item->getName(), $user->getDisplayName());
            }
            return \sprintf('%s "%s" is not assigned to %s. A manager or lead assigns it on the "Project mapping" page.', $this->getLevelName($item), $item->getName(), $user->getDisplayName());
        }

        return null;
    }

    /**
     * The activity a time entry gets for the picked item, or null when the item has none.
     */
    public function getActivityFor(int $itemId): ?Activity
    {
        try {
            $item = $this->find($itemId);
        } catch (\Exception) {
            return null;
        }
        if ($item === null || $item->getProject() === null) {
            return null;
        }

        $step = $item->getPath()[$this->getActivityLevel($item->getProject())] ?? null;

        return $step?->getActivity();
    }

    /**
     * Brings a time entry in line with the item picked on it, right before it is saved: the names of the
     * levels (shown in the lists and exports), the task and the activity all follow the item.
     */
    public function apply(Timesheet $timesheet): void
    {
        $project = $timesheet->getProject();
        if ($project === null) {
            return;
        }

        $meta = $timesheet->getMetaField(self::META_ITEM);
        $value = $meta !== null ? (string) $meta->getValue() : '';

        if (!$this->usesItems($project)) {
            // moved to "Non-Project Activities": the item and its names go
            if ($value !== '') {
                $this->setMeta($timesheet, self::META_ITEM, null);
                foreach (array_keys(self::META_LEVELS) as $name) {
                    $this->setMeta($timesheet, $name, null);
                }
            }
            // Entries made where no phase is picked (Bulk Entry (Week)) get the phase their activity belongs to,
            // so lists and exports show it like for entries made on Log Time.
            $phaseMeta = $timesheet->getMetaField(\App\Entity\Phase::TIMESHEET_META_FIELD);
            if (($phaseMeta === null || (string) $phaseMeta->getValue() === '') && $timesheet->getActivity()?->getId() !== null) {
                $phase = $this->entityManager->getConnection()->fetchOne(
                    'SELECT p.name FROM kimai2_phases p JOIN kimai2_phase_activities pa ON pa.phase_id = p.id
                     WHERE pa.activity_id = ? AND (p.project_id = ? OR p.project_id IS NULL)
                     ORDER BY p.project_id IS NULL, p.position, p.name LIMIT 1',
                    [(int) $timesheet->getActivity()->getId(), (int) $project->getId()]
                );
                if (\is_string($phase) && $phase !== '') {
                    $this->setMeta($timesheet, \App\Entity\Phase::TIMESHEET_META_FIELD, $phase);
                }
            }

            return;
        }

        if ($value === '' || !ctype_digit($value)) {
            return;
        }

        try {
            $item = $this->find((int) $value);
        } catch (\Exception) {
            return;
        }
        if ($item === null) {
            return;
        }
        if ($item->getProject()?->getId() !== $project->getId()) {
            // the entry was moved to another project (several entries can be moved at once from the list):
            // the item of the old project does not fit any more, so it goes together with its names
            $this->setMeta($timesheet, self::META_ITEM, null);
            foreach (array_keys(self::META_LEVELS) as $name) {
                $this->setMeta($timesheet, $name, null);
            }

            return;
        }

        $path = $item->getPath();
        $activityLevel = $this->getActivityLevel($project);
        $names = array_keys(self::META_LEVELS);

        if ($this->getModel($project) === self::PRESALES) {
            // Lead > Phase: the phase goes to the Phase column that "Non-Project Activities" uses as well
            $this->setMeta($timesheet, $names[0], isset($path[0]) ? (string) $path[0]->getName() : null);
            $this->setMeta($timesheet, $names[1], null);
            $this->setMeta($timesheet, $names[2], null);
            $this->setMeta($timesheet, Phase::TIMESHEET_META_FIELD, isset($path[1]) ? (string) $path[1]->getName() : null);
        } else {
            foreach ($names as $level => $name) {
                $this->setMeta($timesheet, $name, isset($path[$level]) ? (string) $path[$level]->getName() : null);
            }
            $this->setMeta($timesheet, Phase::TIMESHEET_META_FIELD, null);
        }

        $task = $path[$activityLevel + 1] ?? null;
        $this->setMeta($timesheet, Task::TIMESHEET_META_FIELD, $task !== null ? (string) $task->getName() : null);

        $activity = ($path[$activityLevel] ?? null)?->getActivity();
        if ($activity !== null && $timesheet->getActivity()?->getId() !== $activity->getId()) {
            $timesheet->setActivity($activity);
        }
    }

    private function setMeta(Timesheet $timesheet, string $name, ?string $value): void
    {
        $meta = $timesheet->getMetaField($name);
        if ($meta === null) {
            if ($value === null) {
                return;
            }
            $meta = new TimesheetMeta();
            $meta->setName($name);
            $timesheet->setMetaField($meta);
        }
        $meta->setValue($value);
    }
}
