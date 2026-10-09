<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\WorkItem;
use App\Form\WorkItemEditForm;
use App\Utils\PageSetup;
use App\WorkModel\WorkItemImportService;
use App\WorkModel\WorkModelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Project mapping: managers and leads build what their people book time on, below the projects the
 * administrator created (Epic > Feature > User Story > Activity > Task and the like, see WorkModelService),
 * and assign each item to people.
 */
#[Route(path: '/project-mapping')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class WorkItemController extends AbstractController
{
    public function __construct(
        private readonly WorkModelService $models,
        private readonly WorkItemImportService $import,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    #[Route(path: '/', name: 'work_items', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $this->denyUnlessManager();

        $projects = $this->models->getManageableProjects($user);
        $project = null;
        $wanted = $request->query->getInt('project');
        foreach ($projects as $candidate) {
            if ($candidate->getId() === $wanted) {
                $project = $candidate;
            }
        }
        if ($project === null && $projects !== []) {
            $project = $projects[0];
        }

        return $this->render('work-items/index.html.twig', [
            'page_setup' => new PageSetup('Task Creation'),
            'projects' => $projects,
            'project' => $project,
            'levels' => $project !== null ? $this->models->getLevels($project) : [],
            'rows' => $project !== null ? $this->rows($project, $this->models->getChildren($project, null)) : [],
            'total' => $project !== null ? $this->models->countItems($project) : 0,
            'people' => $this->models->getAssignableUsers($user),
            // who gets everything of this project: the people in its teams (Team Mapping); null when it has no team
            'project_people' => $project !== null ? $this->projectPeople($project) : null,
        ]);
    }

    /**
     * @return array<User>|null
     */
    private function projectPeople(Project $project): ?array
    {
        $ids = $this->models->getProjectMembers([(int) $project->getId()])[(int) $project->getId()] ?? null;
        if ($ids === null) {
            return null;
        }
        $people = $ids !== [] ? $this->entityManager->getRepository(User::class)->findBy(['id' => $ids]) : [];
        usort($people, static fn (User $a, User $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $people;
    }

    /**
     * One level of the tree, as table rows: the page asks for it when an item is opened.
     */
    #[Route(path: '/children', name: 'work_items_children', methods: ['GET'])]
    public function children(Request $request): Response
    {
        $this->denyUnlessManager();

        $parent = null;
        $parentId = $request->query->getInt('parent');
        if ($parentId > 0) {
            $parent = $this->load($parentId);
            $project = $parent->getProject();
        } else {
            $project = $this->entityManager->find(Project::class, $request->query->getInt('project'));
            if ($project === null) {
                throw $this->createNotFoundException('Project not found');
            }
            $this->denyUnlessProject($project);
        }

        return $this->render('work-items/_rows.html.twig', [
            'project' => $project,
            'rows' => $this->rows($project, $this->models->getChildren($project, $parent)),
            'flat' => false,
            'more' => false,
        ]);
    }

    /**
     * The items whose name holds the search text, or that are assigned to the picked person, as table rows.
     */
    #[Route(path: '/search', name: 'work_items_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $this->denyUnlessManager();

        $project = $this->entityManager->find(Project::class, $request->query->getInt('project'));
        if ($project === null) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyUnlessProject($project);

        $person = null;
        $personId = $request->query->getInt('user');
        if ($personId > 0) {
            $person = $this->entityManager->find(User::class, $personId);
        }

        $limit = 200;
        $items = $this->models->search($project, trim((string) $request->query->get('q', '')), $person, $limit);

        return $this->render('work-items/_rows.html.twig', [
            'project' => $project,
            'rows' => $this->rows($project, $items),
            'flat' => true,
            'more' => \count($items) >= $limit,
        ]);
    }

    /**
     * Changes who the ticked items are assigned to.
     */
    #[Route(path: '/assign', name: 'work_items_assign', methods: ['POST'])]
    public function assign(Request $request): Response
    {
        $user = $this->getUser();
        $this->denyUnlessManager();

        $project = $this->entityManager->find(Project::class, $request->request->getInt('project'));
        if ($project === null) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyUnlessProject($project);
        $back = $this->redirectToRoute('work_items', ['project' => $project->getId()]);

        if (!$this->isCsrfTokenValid('work_item_assign', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $back;
        }

        $mode = (string) $request->request->get('mode', 'add');
        if (!\in_array($mode, ['add', 'replace', 'remove', 'clear'], true)) {
            $mode = 'add';
        }

        $items = [];
        foreach ($request->request->all('items') as $id) {
            $item = $this->models->find((int) $id);
            if ($item !== null && $item->getProject()?->getId() === $project->getId()) {
                $items[] = $item;
            }
        }

        $allowed = [];
        foreach ($this->models->getAssignableUsers($user) as $person) {
            $allowed[(int) $person->getId()] = $person;
        }
        $people = [];
        foreach ($request->request->all('users') as $id) {
            if (isset($allowed[(int) $id])) {
                $people[] = $allowed[(int) $id];
            }
        }

        if ($items === [] || ($people === [] && $mode !== 'clear')) {
            $this->flashError('Please tick the items and pick the people first.');

            return $back;
        }

        try {
            $this->models->assign($items, $people, $mode);
            $this->flashSuccess('action.update.success');
        } catch (\Exception $ex) {
            $this->flashUpdateException($ex);
        }

        return $back;
    }

    /**
     * The Excel file of a project: the empty template, or (with "data") the current mapping in the same format.
     */
    #[Route(path: '/template', name: 'work_items_template', methods: ['GET'])]
    public function template(Request $request): Response
    {
        $this->denyUnlessManager();

        $project = $this->entityManager->find(Project::class, $request->query->getInt('project'));
        if ($project === null) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyUnlessProject($project);

        $withData = $request->query->getBoolean('data');
        $file = $this->import->createFile($project, $this->getUser(), $withData);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $project->getName());

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'project-mapping-' . trim((string) $name, '-') . ($withData ? '' : '-template') . '.xlsx');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Bulk upload of the mapping of one project from an Excel file (see WorkItemImportService for the rules).
     */
    #[Route(path: '/upload', name: 'work_items_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $user = $this->getUser();
        $this->denyUnlessManager();

        $project = $this->entityManager->find(Project::class, $request->query->getInt('project'));
        if ($project === null) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyUnlessProject($project);

        $result = null;
        $fileName = null;
        $empty = ['created' => 0, 'assigned' => 0, 'rows' => 0, 'errors' => []];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('work_item_upload', (string) $request->request->get('_token'))) {
                $this->flashError('action.csrf.error');

                return $this->redirectToRoute('work_items_upload', ['project' => $project->getId()]);
            }

            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $result = array_merge($empty, ['errors' => [1 => ['Please choose the Excel file to upload.']]]);
            } elseif (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                $result = array_merge($empty, ['errors' => [1 => ['Only Excel files (.xlsx) can be uploaded. Please start from the template.']]]);
            } else {
                $fileName = $file->getClientOriginalName();
                try {
                    $result = $this->import->import($project, $user, $file->getPathname());
                } catch (\Throwable $ex) {
                    $result = array_merge($empty, ['errors' => [1 => ['The file could not be read: ' . $ex->getMessage()]]]);
                }
            }
        }

        return $this->render('work-items/upload.html.twig', [
            'page_setup' => new PageSetup('Task Creation'),
            'project' => $project,
            'levels' => $this->models->getLevels($project),
            'headers' => $this->import->getHeaders($project),
            'example' => $this->import->exampleRows($this->models->getLevels($project), $user),
            'result' => $result,
            'file_name' => $fileName,
            'max_rows' => WorkItemImportService::MAX_ROWS,
            'people' => $this->models->getAssignableUsers($user),
        ]);
    }

    #[Route(path: '/create', name: 'work_items_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnlessManager();

        $project = $this->entityManager->find(Project::class, $request->query->getInt('project'));
        if ($project === null) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyUnlessProject($project);

        $item = new WorkItem();
        $item->setProject($project);
        $item->setCreatedBy($this->getUser());

        $parentId = $request->query->getInt('parent');
        if ($parentId > 0) {
            $parent = $this->models->find($parentId);
            if ($parent === null || $parent->getProject()?->getId() !== $project->getId()) {
                throw $this->createNotFoundException('Item not found');
            }
            $item->setParent($parent);
        }

        if (!isset($this->models->getLevels($project)[$item->getLevel()])) {
            throw $this->createNotFoundException('Nothing can be added below a task');
        }

        return $this->edit($item, $request, $this->generateUrl('work_items_create', ['project' => $project->getId(), 'parent' => $parentId > 0 ? $parentId : null]));
    }

    #[Route(path: '/{id}/edit', name: 'work_items_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyUnlessManager();
        $item = $this->load($id);

        return $this->edit($item, $request, $this->generateUrl('work_items_edit', ['id' => $id]));
    }

    #[Route(path: '/{id}/delete', name: 'work_items_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyUnlessManager();
        $item = $this->load($id);
        $back = $this->redirectToRoute('work_items', ['project' => $item->getProject()?->getId()]);

        if (!$this->isCsrfTokenValid('work_item_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $back;
        }

        try {
            $this->models->delete($item);
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $back;
    }

    private function denyUnlessManager(): void
    {
        if (!$this->models->canManage($this->getUser())) {
            throw $this->createAccessDeniedException('Only managers and leads can map projects');
        }
    }

    private function denyUnlessProject(Project $project): void
    {
        if (!$this->models->canManageProject($this->getUser(), $project)) {
            throw $this->createAccessDeniedException('You cannot map this project');
        }
    }

    private function load(int $id): WorkItem
    {
        $item = $this->models->find($id);
        if ($item === null || $item->getProject() === null) {
            throw $this->createNotFoundException('Item not found');
        }
        $this->denyUnlessProject($item->getProject());

        return $item;
    }

    private function edit(WorkItem $item, Request $request, string $action): Response
    {
        $user = $this->getUser();
        $project = $item->getProject();
        $levels = $this->models->getLevels($project);
        $levelName = $levels[$item->getLevel()] ?? 'Item';
        $parentName = $item->getParent() !== null ? ($levels[$item->getLevel() - 1] ?? 'item') : null;
        $isNew = $item->getId() === null;

        // people already on the item stay selectable, even those this person could not assign themselves
        $assignees = $this->models->getAssignableUsers($user);
        foreach ($item->getUsers() as $assigned) {
            if (!\in_array($assigned, $assignees, true)) {
                $assignees[] = $assigned;
            }
        }

        $form = $this->createForm(WorkItemEditForm::class, $item, [
            'action' => $action,
            'method' => 'POST',
            'assignees' => $assignees,
            'level_name' => $levelName,
            'parent_name' => $parentName,
            'several' => $isNew,
            // the names of new items arrive as lines of one text box and are checked below, one by one
            'validation_groups' => $isNew ? false : null,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // a new item can come as several names, one per line: each becomes an item with the same people
            $names = [(string) $item->getName()];
            if ($isNew) {
                $names = [];
                foreach (preg_split('/\R/u', (string) $form->get('names')->getData()) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && !\in_array(mb_strtolower($line), array_map('mb_strtolower', $names), true)) {
                        $names[] = $line;
                    }
                }
            }

            $field = $form->get($isNew ? 'names' : 'name');
            $items = [];
            foreach ($names as $name) {
                $candidate = $item;
                if ($isNew) {
                    $candidate = new WorkItem();
                    $candidate->setProject($project);
                    $candidate->setParent($item->getParent());
                    $candidate->setCreatedBy($user);
                    // foreach ($item->getUsers() as $assigned) {
                    //     $candidate->addUser($assigned);
                    // }
                    $candidate->setName($name);
                }
                if (mb_strlen($name) > 150) {
                    $field->addError(new FormError(\sprintf('"%s…" is longer than 150 characters.', mb_substr($name, 0, 30))));
                } elseif (ctype_digit($name)) {
                    $field->addError(new FormError(\sprintf('"%s" is just a number. Please use a name.', $name)));
                } elseif ($this->models->nameExists($candidate)) {
                    $field->addError(new FormError(\sprintf('There is already a %s called "%s" here.', $levelName, $name)));
                }
                $items[] = $candidate;
            }
            if ($items === []) {
                $field->addError(new FormError('Please enter a name.'));
            }

            if ($field->getErrors()->count() === 0) {
                try {
                    foreach ($items as $candidate) {
                        $this->models->prepare($candidate);
                    }
                    $this->entityManager->flush();
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute('work_items', ['project' => $project->getId()]);
                } catch (\Exception $ex) {
                    $this->handleFormUpdateException($ex, $form);
                }
            }
        }

        return $this->render('work-items/edit.html.twig', [
            'page_setup' => new PageSetup('Task Creation'),
            'item' => $item,
            'project' => $project,
            'level_name' => $levelName,
            'path' => $item->getParent() !== null ? $item->getParent()->getPath() : [],
            'levels' => $levels,
            'form' => $form->createView(),
        ]);
    }

    /**
     * What the table shows for each item.
     *
     * @param array<WorkItem> $items
     * @return array<int, array<string, mixed>>
     */
    private function rows(Project $project, array $items): array
    {
        $levels = $this->models->getLevels($project);
        $counts = $this->models->countChildren($items);
        $below = $this->models->getPeopleBelow($project, $items);

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'item' => $item,
                'level_name' => $levels[$item->getLevel()] ?? 'Item',
                'child_name' => $levels[$item->getLevel() + 1] ?? null,
                'parent_name' => $item->getParent() !== null ? ($levels[$item->getLevel() - 1] ?? 'item') : null,
                'effective' => $item->getEffectiveUsers(),
                'children' => $counts[(int) $item->getId()] ?? 0,
                // nobody on the item itself, but people on what is below it
                'below' => $below[(int) $item->getId()] ?? [],
            ];
        }

        return $rows;
    }
}
