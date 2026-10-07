<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\ActivityTask;
use App\Entity\Phase;
use App\Form\ActivityTaskEditForm;
use App\Form\PhaseEditForm;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration of phases (project > phase > activity > task).
 * The route names start with "admin_phase": AdminOnlyAreaSubscriber closes them to everybody except administrators.
 */
#[Route(path: '/admin/phases')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PhaseController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route(path: '/', name: 'admin_phase', methods: ['GET'])]
    public function index(): Response
    {
        $phases = $this->entityManager->getRepository(Phase::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);

        // one block per project, the phases for "all projects" last
        $groups = [];
        foreach ($phases as $phase) {
            $key = $phase->getProject() !== null ? $phase->getProject()->getName() : '';
            $groups[$key][] = $phase;
        }
        uksort($groups, static fn (string $a, string $b) => ($a === '') <=> ($b === '') ?: strcasecmp($a, $b));

        // standard tasks, one block per activity
        $tasks = [];
        foreach ($this->entityManager->getRepository(ActivityTask::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']) as $task) {
            $tasks[(string) $task->getActivity()?->getName()][] = $task;
        }
        ksort($tasks, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->render('phases/index.html.twig', [
            'page_setup' => new PageSetup('Phases'),
            'groups' => $groups,
            'tasks' => $tasks,
        ]);
    }

    #[Route(path: '/create', name: 'admin_phase_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->edit(new Phase(), $request, $this->generateUrl('admin_phase_create'));
    }

    #[Route(path: '/{id}/edit', name: 'admin_phase_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function update(int $id, Request $request): Response
    {
        return $this->edit($this->load($id), $request, $this->generateUrl('admin_phase_edit', ['id' => $id]));
    }

    #[Route(path: '/{id}/delete', name: 'admin_phase_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $phase = $this->load($id);

        if (!$this->isCsrfTokenValid('phase_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('admin_phase');
        }

        try {
            // time entries keep the phase name they were saved with
            $this->entityManager->remove($phase);
            $this->entityManager->flush();
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $this->redirectToRoute('admin_phase');
    }

    #[Route(path: '/task/create', name: 'admin_phase_task_create', methods: ['GET', 'POST'])]
    public function createTask(Request $request): Response
    {
        return $this->editTask(new ActivityTask(), $request, $this->generateUrl('admin_phase_task_create'));
    }

    #[Route(path: '/task/{id}/edit', name: 'admin_phase_task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function updateTask(int $id, Request $request): Response
    {
        return $this->editTask($this->loadTask($id), $request, $this->generateUrl('admin_phase_task_edit', ['id' => $id]));
    }

    #[Route(path: '/task/{id}/delete', name: 'admin_phase_task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteTask(int $id, Request $request): Response
    {
        $task = $this->loadTask($id);

        if (!$this->isCsrfTokenValid('phase_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('admin_phase');
        }

        try {
            // time entries keep the task name they were saved with
            $this->entityManager->remove($task);
            $this->entityManager->flush();
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $this->redirectToRoute('admin_phase');
    }

    private function loadTask(int $id): ActivityTask
    {
        $task = $this->entityManager->getRepository(ActivityTask::class)->find($id);
        if ($task === null) {
            throw $this->createNotFoundException('Task not found');
        }

        return $task;
    }

    private function editTask(ActivityTask $task, Request $request, string $action): Response
    {
        $form = $this->createForm(ActivityTaskEditForm::class, $task, ['action' => $action, 'method' => 'POST']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->persist($task);
                $this->entityManager->flush();
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('admin_phase');
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('phases/edit-task.html.twig', [
            'page_setup' => new PageSetup('Phases'),
            'task' => $task,
            'form' => $form->createView(),
        ]);
    }

    private function load(int $id): Phase
    {
        $phase = $this->entityManager->getRepository(Phase::class)->find($id);
        if ($phase === null) {
            throw $this->createNotFoundException('Phase not found');
        }

        return $phase;
    }

    private function edit(Phase $phase, Request $request, string $action): Response
    {
        $form = $this->createForm(PhaseEditForm::class, $phase, ['action' => $action, 'method' => 'POST']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->persist($phase);
                $this->entityManager->flush();
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('admin_phase');
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('phases/edit.html.twig', [
            'page_setup' => new PageSetup('Phases'),
            'phase' => $phase,
            'form' => $form->createView(),
        ]);
    }
}
