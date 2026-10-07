<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Task;
use App\Form\TaskEditForm;
use App\Task\TaskService;
use App\Utils\PageSetup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tasks: a manager or lead assigns work to a person, who then works it off.
 */
#[Route(path: '/tasks')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TaskController extends AbstractController
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    #[Route(path: '/', name: 'tasks', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $myTasks = $this->tasks->getMyTasks($user);
        // opening this page counts as having seen the newly assigned tasks (see the notification bell)
        $this->tasks->markTasksSeen($user);
        $teamTasks = $this->tasks->getTeamTasks($user);

        $editable = [];
        foreach ($teamTasks as $task) {
            $editable[$task->getId()] = $this->tasks->canEdit($user, $task);
        }
        foreach ($myTasks as $task) {
            $editable[$task->getId()] = $this->tasks->canEdit($user, $task);
        }

        return $this->render('tasks/index.html.twig', [
            'page_setup' => new PageSetup('Tasks'),
            'my_tasks' => $myTasks,
            'team_tasks' => $teamTasks,
            'logged' => $this->tasks->getLoggedSeconds(array_merge($myTasks, $teamTasks)),
            'editable' => $editable,
            'can_assign' => $this->tasks->canAssign($user),
        ]);
    }

    #[Route(path: '/create', name: 'tasks_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if (!$this->tasks->canAssign($user)) {
            throw $this->createAccessDeniedException('Only managers and leads can assign tasks');
        }

        $task = new Task();
        $task->setCreatedBy($user);

        return $this->edit($task, $request, $this->generateUrl('tasks_create'));
    }

    #[Route(path: '/{id}/edit', name: 'tasks_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function update(int $id, Request $request): Response
    {
        $task = $this->load($id);
        if (!$this->tasks->canEdit($this->getUser(), $task)) {
            throw $this->createAccessDeniedException('You cannot change this task');
        }

        return $this->edit($task, $request, $this->generateUrl('tasks_edit', ['id' => $id]));
    }

    /**
     * The assignee (or a manager) moves a task between open, in progress and done.
     */
    #[Route(path: '/{id}/status/{status}', name: 'tasks_status', requirements: ['id' => '\d+', 'status' => 'open|in_progress|done'], methods: ['POST'])]
    public function status(int $id, string $status, Request $request): Response
    {
        $task = $this->load($id);
        if (!$this->tasks->canChangeStatus($this->getUser(), $task)) {
            throw $this->createAccessDeniedException('You cannot change this task');
        }

        if (!$this->isCsrfTokenValid('task_status', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('tasks');
        }

        try {
            $task->setStatus($status);
            $this->tasks->save($task);
            $this->flashSuccess('action.update.success');
        } catch (\Exception $ex) {
            $this->flashUpdateException($ex);
        }

        return $this->redirectToRoute('tasks');
    }

    #[Route(path: '/{id}/delete', name: 'tasks_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $task = $this->load($id);
        if (!$this->tasks->canEdit($this->getUser(), $task)) {
            throw $this->createAccessDeniedException('You cannot delete this task');
        }

        if (!$this->isCsrfTokenValid('task_status', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('tasks');
        }

        try {
            $this->tasks->delete($task);
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $this->redirectToRoute('tasks');
    }

    private function load(int $id): Task
    {
        $task = $this->tasks->find($id);
        if ($task === null) {
            throw $this->createNotFoundException('Task not found');
        }

        return $task;
    }

    private function edit(Task $task, Request $request, string $action): Response
    {
        $user = $this->getUser();

        // keep the current assignee and project selectable, even if they would not be offered for a new task
        $assignees = $this->tasks->getAssignableUsers($user);
        if ($task->getAssignee() !== null && !\in_array($task->getAssignee(), $assignees, true)) {
            $assignees[] = $task->getAssignee();
        }
        $projects = $this->tasks->getProjects($user);
        if ($task->getProject() !== null && !\in_array($task->getProject(), $projects, true)) {
            $projects[] = $task->getProject();
        }

        $form = $this->createForm(TaskEditForm::class, $task, [
            'action' => $action,
            'method' => 'POST',
            'projects' => $projects,
            'assignees' => $assignees,
            // lets the form offer only the activities of the picked project
            'activity_projects' => $this->tasks->getActivityProjects($projects),
        ]);

        $previousAssignee = $task->getId() !== null ? $task->getAssignee()?->getId() : null;

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // a new task, or a task handed to somebody else, shows up in that person's notification bell
                // (not when people give a task to themselves)
                if ($task->getAssignee()?->getId() !== $previousAssignee) {
                    $task->setAssigneeSeen($task->getAssignee()?->getId() === $user->getId());
                }
                // re-apply the status through the setter so the completion time is kept in step
                $task->setStatus($task->getStatus());
                $this->tasks->save($task);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('tasks');
            } catch (\Exception $ex) {
                $this->handleFormUpdateException($ex, $form);
            }
        }

        return $this->render('tasks/edit.html.twig', [
            'page_setup' => new PageSetup('Tasks'),
            'task' => $task,
            'form' => $form->createView(),
        ]);
    }
}
