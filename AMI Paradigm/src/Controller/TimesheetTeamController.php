<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Event\TimesheetMetaDisplayEvent;
use App\Export\ServiceExport;
use App\Form\Model\MultiUserTimesheet;
use App\Form\TimesheetAdminEditForm;
use App\Form\TimesheetMultiUserEditForm;
use App\Repository\Query\TimesheetQuery;
use App\Repository\Query\TimesheetQueryHint;
use App\Utils\PageSetup;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * No permission check on controller level, only for single routes.
 *
 * There was "view_other_timesheet" here once, but it is a bug.
 * Some companies (rarely, but existing) want their employees to enter time, but not to see it afterward.
 *
 * It is legit to only own "create_other_timesheet" without "view_other_timesheet".
 */
#[Route(path: '/team/timesheet')]
final class TimesheetTeamController extends TimesheetAbstractController
{
    #[Route(path: '/', defaults: ['page' => 1], name: 'admin_timesheet', methods: ['GET'])]
    #[Route(path: '/page/{page}', requirements: ['page' => '[1-9]\d*'], name: 'admin_timesheet_paginated', methods: ['GET'])]
    #[IsGranted('view_other_timesheet')]
    public function indexAction(int $page, Request $request): Response
    {
        $query = $this->createDefaultQuery();
        $query->addAllowedOrderColumns('user');
        $query->setPage($page);

        return $this->index($query, $request, 'admin_timesheet', 'admin_timesheet_paginated', TimesheetMetaDisplayEvent::TEAM_TIMESHEET);
    }

    #[Route(path: '/export/{exporter}', name: 'admin_timesheet_export', methods: ['GET', 'POST'])]
    #[IsGranted('export_other_timesheet')]
    public function exportAction(string $exporter, Request $request, ServiceExport $serviceExport): Response
    {
        return $this->export($exporter, $request, $serviceExport);
    }

    #[Route(path: '/{id}/edit', name: 'admin_timesheet_edit', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'entry')]
    public function editAction(Timesheet $entry, Request $request): Response
    {
        return $this->edit($entry, $request);
    }

    #[Route(path: '/{id}/duplicate', name: 'admin_timesheet_duplicate', methods: ['GET', 'POST'])]
    #[IsGranted('duplicate', 'entry')]
    public function duplicateAction(Timesheet $entry, Request $request): Response
    {
        return $this->duplicate($entry, $request);
    }

    #[Route(path: '/create', name: 'admin_timesheet_create', methods: ['GET', 'POST'])]
    #[IsGranted('create_other_timesheet')]
    public function createAction(Request $request): Response
    {
        return $this->create($request);
    }

    #[Route(path: '/create_mu', name: 'admin_timesheet_create_multiuser', methods: ['GET', 'POST'])]
    #[IsGranted('create_other_timesheet')]
    public function createForMultiUserAction(Request $request): Response
    {
        $entry = new MultiUserTimesheet();
        $entry->setUser($this->getUser());
        $this->service->prepareNewTimesheet($entry, $request);

        $createForm = $this->getMultiUserCreateForm($entry);
        $createForm->handleRequest($request);

        if ($createForm->isSubmitted() && $createForm->isValid()) {
            try {
                /** @var ArrayCollection<User> $users */
                $users = $createForm->get('users')->getData();
                /** @var ArrayCollection<Team> $teams */
                // $teams = $createForm->get('teams')->getData();
                // the Team box is switched off (TimesheetMultiUserEditForm): no teams then
                $teams = $createForm->has('teams') ? $createForm->get('teams')->getData() : new ArrayCollection();

                /** @var array<User> $allUsers */
                $allUsers = $users->toArray();
                /** @var Team $team */
                foreach ($teams as $team) {
                    $allUsers = array_merge($allUsers, $team->getUsers());
                }
                $allUsers = array_unique($allUsers);

                /** @var Tag[] $tags */
                $tags = [];
                /** @var Tag $tag */
                foreach ($entry->getTags() as $tag) {
                    $entry->addTag($tag);
                    $tags[] = $tag;
                }

                // weekly cut-off for every person before anything is saved: one's own entry by the end of its week,
                // one's people's a week later (see WeeklyCutoffService)
                $cutoffProblems = [];
                foreach ($allUsers as $user) {
                    if ($entry->getBegin() !== null && ($message = $this->weeklyCutoff->getMessage($this->getUser(), $user, $entry->getBegin())) !== null) {
                        $cutoffProblems[] = $message;
                    }
                }
                if ($cutoffProblems !== []) {
                    foreach (array_unique($cutoffProblems) as $message) {
                        $createForm->get('begin_date')->addError(new \Symfony\Component\Form\FormError($message));
                    }

                    return $this->render('timesheet/edit.html.twig', [
                        'timesheet' => $entry,
                        'form' => $createForm->createView(),
                        'template' => $this->getTrackingMode()->getEditTemplate(),
                    ]);
                }

                $newTimesheets = [];
                foreach ($allUsers as $user) {
                    $newTimesheet = $entry->createCopy();
                    $newTimesheet->setUser($user);
                    foreach ($tags as $tag) {
                        $newTimesheet->addTag($tag);
                    }
                    $this->service->prepareNewTimesheet($newTimesheet, $request);
                    $this->service->validateTimesheet($newTimesheet);
                    $newTimesheets[] = $newTimesheet;
                }

                foreach ($newTimesheets as $newTimesheet) {
                    $this->service->saveTimesheet($newTimesheet);
                }

                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute($this->getTimesheetRoute());
            } catch (\Exception $ex) {
                // FIXME I guess this will save timesheets for some users, but then fail only for single users
                // FIXME we should run in a transaction or disallow to create running timesheets
                $this->handleFormUpdateException($ex, $createForm);
            }
        }

        return $this->render('timesheet/edit.html.twig', [
            'timesheet' => $entry,
            'form' => $createForm->createView(),
            'template' => $this->getTrackingMode()->getEditTemplate(),
        ]);
    }

    protected function getMultiUserCreateForm(MultiUserTimesheet $entry): FormInterface
    {
        $mode = $this->getTrackingMode();

        return $this->createForm(TimesheetMultiUserEditForm::class, $entry, [
            'action' => $this->generateUrl('admin_timesheet_create_multiuser'),
            'include_rate' => $this->isGranted($this->getPermissionEditRate()),
            'include_exported' => $this->isGranted($this->getPermissionEditExport()),
            'include_billable' => $this->isGranted($this->getPermissionEditBillable()),
            'include_user' => $this->includeUserInForms('create'),
            'allow_begin_datetime' => $mode->canEditBegin(),
            'allow_end_datetime' => $mode->canEditEnd(),
            'allow_duration' => $mode->canEditDuration(),
            'duration_minutes' => $this->configuration->getTimesheetIncrementDuration(),
            'timezone' => $this->getDateTimeFactory()->getTimezone()->getName(),
            'customer' => true,
        ]);
    }

    #[Route(path: '/multi-update', name: 'admin_timesheet_multi_update', methods: ['POST'])]
    #[IsGranted('edit_other_timesheet')]
    public function multiUpdateAction(Request $request): Response
    {
        return $this->multiUpdate($request);
    }

    #[Route(path: '/multi-delete', name: 'admin_timesheet_multi_delete', methods: ['POST'])]
    #[IsGranted('delete_other_timesheet')]
    public function multiDeleteAction(Request $request): Response
    {
        return $this->multiDelete($request);
    }

    protected function prepareQuery(TimesheetQuery $query): void
    {
        $query->setCurrentUser($this->getUser());
        $query->addQueryHint(TimesheetQueryHint::USER_PREFERENCES); // e.g. for latest approval
    }

    protected function getCreateForm(Timesheet $entry): FormInterface
    {
        return $this->generateCreateForm($entry, TimesheetAdminEditForm::class, $this->generateUrl('admin_timesheet_create'));
    }

    protected function getDuplicateForm(Timesheet $entry, Timesheet $original): FormInterface
    {
        return $this->generateCreateForm($entry, TimesheetAdminEditForm::class, $this->generateUrl('admin_timesheet_duplicate', ['id' => $original->getId()]));
    }

    protected function getPermissionEditExport(): string
    {
        return 'edit_export_other_timesheet';
    }

    protected function getPermissionEditBillable(): string
    {
        return 'edit_billable_other_timesheet';
    }

    protected function getPermissionEditRate(): string
    {
        return 'edit_rate_other_timesheet';
    }

    protected function getEditFormClassName(): string
    {
        return TimesheetAdminEditForm::class;
    }

    protected function includeUserInForms(string $formName): bool
    {
        if ($formName === 'toolbar') {
            return true;
        }

        if ($formName === 'create') {
            return $this->isGranted('create_other_timesheet');
        }

        return $this->isGranted('edit_other_timesheet');
    }

    protected function getTimesheetRoute(): string
    {
        return 'admin_timesheet';
    }

    protected function getEditRoute(): string
    {
        return 'admin_timesheet_edit';
    }

    protected function getMultiUpdateRoute(): string
    {
        return 'admin_timesheet_multi_update';
    }

    protected function getMultiDeleteRoute(): string
    {
        return 'admin_timesheet_multi_delete';
    }

    protected function canSeeStartEndTime(): bool
    {
        return true;
    }

    protected function getQueryNamePrefix(): string
    {
        return 'TeamTimes';
    }

    protected function canSeeRate(): bool
    {
        return $this->isGranted('view_rate_other_timesheet');
    }

    protected function canSeeUsername(): bool
    {
        return true;
    }

    protected function hasMarkdownSupport(): bool
    {
        return false;
    }

    protected function getTableName(): string
    {
        return 'timesheet_admin';
    }

    protected function getActionName(): string
    {
        return 'timesheets_team';
    }

    protected function getActionNameSingle(): string
    {
        return 'timesheet_team';
    }

    protected function createPageSetup(): PageSetup
    {
        $page = new PageSetup('all_times');
        $page->setHelp('timesheet.html');

        return $page;
    }

    /**
     * "Project" tab: the projects the logged-in person works with (Task Creation) and the ones their people logged
     * time on, by name (Pre-Sales and Non-Project Activities have their own tabs).
     *
     * @return array<int, string> project names keyed by ID
     */
    protected function getProjectChoices(TimesheetQuery $query, string $view): array
    {
        if ($view !== self::VIEW_PROJECT) {
            return [];
        }

        $presales = $this->workModels->getPresalesProject(false);
        $nonProject = $this->workModels->getNonProject();
        $special = array_filter([$presales?->getId(), $nonProject?->getId()]);

        $projects = [];
        foreach ($this->workModels->getManageableProjects($this->getUser()) as $project) {
            if (!\in_array($project->getId(), $special, true)) {
                $projects[(int) $project->getId()] = (string) $project->getName();
            }
        }

        // the projects of this tab the team logged time on
        $logged = clone $query;
        $logged->setProjects($this->getViewProjectIds(self::VIEW_PROJECT) ?: [0]);
        foreach ($this->repository->getDurationsGroupedBy($logged, 'project') as $row) {
            if ($row['key'] !== null && !\in_array((int) $row['key'], $special, true)) {
                $projects[(int) $row['key']] ??= $row['label'];
            }
        }
        uasort($projects, static fn ($a, $b) => strcasecmp($a, $b));

        // Pre-Sales and Non-Project Activities have their own tabs, so they are not offered here
        // if ($presales !== null) {
        //     $projects[(int) $presales->getId()] = (string) $presales->getName();
        // }
        // if ($nonProject !== null) {
        //     $projects[(int) $nonProject->getId()] = (string) $nonProject->getName();
        // }

        return $projects;
    }

    private \App\Timesheet\WeeklyCutoffService $weeklyCutoff;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setWeeklyCutoff(\App\Timesheet\WeeklyCutoffService $weeklyCutoff): void
    {
        $this->weeklyCutoff = $weeklyCutoff;
    }

    /** the period of the dashboard, see getDashboardPeriod() */
    private ?array $period = null;

    /**
     * Team Dashboard looks at this week, unless another range was picked (kept for the session).
     * "This Week" goes back to the current week.
     */
    protected function getDashboardPeriod(Request $request, string $context = ''): ?array
    {
        $session = $request->getSession();
        $key = 'team_dashboard_period';
        $timezone = new \DateTimeZone($this->getUser()->getTimezone());

        // a range picked on another tab or project does not carry over: back to this week
        if ($session->get($key . '_context') !== $context) {
            $session->remove($key);
        }

        if ($request->query->get('period') === 'week') {
            $session->remove($key);
        } elseif ($request->query->has('from') && $request->query->has('to')) {
            $from = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('from'), $timezone);
            $to = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('to'), $timezone);
            if ($from !== false && $to !== false) {
                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }
                $session->set($key, [$from->format('Y-m-d'), $to->format('Y-m-d')]);
                $session->set($key . '_context', $context);
            }
        }

        $stored = $session->get($key);
        if (\is_array($stored) && \count($stored) === 2) {
            $begin = new \DateTime($stored[0] . ' 00:00:00', $timezone);
            $end = new \DateTime($stored[1] . ' 23:59:59', $timezone);
            $week = false;
        } else {
            $factory = $this->getDateTimeFactory();
            $begin = $factory->getStartOfWeek();
            $begin->setTime(0, 0, 0);
            $end = $factory->getEndOfWeek();
            $end->setTime(23, 59, 59);
            $week = true;
        }

        return $this->period = ['begin' => $begin, 'end' => $end, 'week' => $week];
    }

    /**
     * Team Dashboard always has its tabs, even before anybody logged time on that kind of work:
     * All, Project, Non-Project and, beside it, Pre-Sales (earlier only the kinds in use were shown).
     *
     * @return array<string, string>
     */
    protected function getListViews(TimesheetQuery $query): array
    {
        $views = [self::VIEW_PROJECT => 'Project', \App\WorkModel\WorkModelService::NON_PROJECT => 'Non-Project'];
        if ($this->workModels->getPresalesProject(false) !== null) {
            $views[\App\WorkModel\WorkModelService::PRESALES] = 'Pre-Sales';
        }

        return $views;
    }

    /**
     * Pre-Sales and Non-Project tabs: their project gets the same charts and export box as a picked project.
     */
    protected function getViewProject(string $view): ?array
    {
        $project = match ($view) {
            \App\WorkModel\WorkModelService::PRESALES => $this->workModels->getPresalesProject(false),
            \App\WorkModel\WorkModelService::NON_PROJECT => $this->workModels->getNonProject(),
            default => null,
        };

        return $project !== null ? [(int) $project->getId(), (string) $project->getName()] : null;
    }

    /**
     * The charts above Team Dashboard:
     * - "All": one pie of all hours, split into projects, Pre-Sales and non-project activities
     * - a project picked on the "Project" tab: its hours per person, per activity and per task
     * Each comes with the export box (time range, user, team; the project is fixed).
     */
    protected function getListSummary(TimesheetQuery $listQuery, string $view, ?string $project): ?array
    {
        if ($view !== 'all' && $project === null) {
            return null;
        }

        $toPie = static function (array $rows, string $empty): array {
            $slices = [];
            foreach ($rows as $row) {
                if ($row['seconds'] <= 0) {
                    continue;
                }
                $slices[] = ['label' => $row['label'] !== '' ? $row['label'] : $empty, 'seconds' => $row['seconds']];
            }
            usort($slices, static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

            return $slices;
        };

        $charts = [];
        if ($project === null) {
            $presalesId = $this->workModels->getPresalesProject(false)?->getId();
            $nonProjectId = $this->workModels->getNonProject()?->getId();
            $parts = ['Projects' => 0, 'Pre-Sales' => 0, 'Non-Project Activities' => 0];
            foreach ($this->repository->getDurationsGroupedBy($listQuery, 'project') as $row) {
                $id = (int) $row['key'];
                $part = $id === $presalesId ? 'Pre-Sales' : ($id === $nonProjectId ? 'Non-Project Activities' : 'Projects');
                $parts[$part] += $row['seconds'];
            }
            $slices = [];
            foreach ($parts as $label => $seconds) {
                $slices[] = ['label' => $label, 'seconds' => $seconds];
            }
            $charts[] = ['title' => 'Total Hours', 'slices' => $slices, 'colors' => ['#206bc4', '#f59f00', '#2fb344']];
        } else {
            $charts[] = ['title' => 'Total Hours on ' . $project, 'slices' => $toPie($this->repository->getDurationsGroupedBy($listQuery, 'user'), 'Unknown'), 'colors' => null];
            $charts[] = ['title' => 'Hours by Activity', 'slices' => $toPie($this->repository->getDurationsGroupedBy($listQuery, 'activity'), 'No activity'), 'colors' => null];
            $charts[] = ['title' => 'Hours by Task', 'slices' => $toPie($this->repository->getDurationsGroupedBy($listQuery, 'task'), 'No task'), 'colors' => null];
        }

        foreach ($charts as $index => $chart) {
            $charts[$index]['total'] = array_sum(array_column($chart['slices'], 'seconds'));
        }

        // The team of the export: on a project of the "Project" tab it is the project's own teams (Team Allocation),
        // on "All" every team; neither is shown. Non-Project and Pre-Sales are shared by everybody: there it is chosen.
        $showTeam = \in_array($view, [\App\WorkModel\WorkModelService::NON_PROJECT, \App\WorkModel\WorkModelService::PRESALES], true);
        $exportTeams = [];
        if ($view === self::VIEW_PROJECT && $project !== null) {
            $projectId = $this->getChosenProjectId($project);
            $exportTeams = $projectId !== null ? $this->workModels->getProjectTeamIds($projectId) : [];
        }

        return [
            'project' => $project,
            'show_team' => $showTeam,
            'export_teams' => $exportTeams,
            'charts' => $charts,
            'export_form' => $this->isGranted('create_export') ? $this->createExportBoxForm()->createView() : null,
        ];
    }

    /**
     * The ID of the project picked on the "Project" tab, by its name (as kept in the session by the list).
     */
    private function getChosenProjectId(string $name): ?int
    {
        $id = $this->container->get('request_stack')->getCurrentRequest()?->getSession()->get('timesheet_project_admin_timesheet');
        if (\is_int($id) || ctype_digit((string) $id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * The fields of the export box, the same as on the Export page (time range, user, team, project), under their own
     * name so they do not clash with the search form of the list.
     */
    private function createExportBoxForm(): FormInterface
    {
        $user = $this->getUser();
        $query = new \App\Repository\Query\ExportQuery();
        // the export starts with the dashboard's period (this week, or the range picked above the charts)
        // $query->setBegin($this->getDateTimeFactory()->getStartOfMonth());
        // $query->setEnd($this->getDateTimeFactory()->getEndOfMonth());
        $query->setBegin($this->period !== null ? clone $this->period['begin'] : $this->getDateTimeFactory()->getStartOfWeek());
        $query->setEnd($this->period !== null ? clone $this->period['end'] : $this->getDateTimeFactory()->getEndOfWeek());
        $query->setCurrentUser($user);

        $teamUsers = null;
        if (!$user->isAdmin() && !$user->isSuperAdmin()) {
            $teamUsers = $this->workModels->getAssignableUsers($user);
            $teamUsers = $teamUsers !== [] ? $teamUsers : [$user];
        }

        return $this->container->get('form.factory')->createNamed('team_export', \App\Form\Toolbar\ExportToolbarForm::class, $query, [
            'method' => 'GET',
            'csrf_protection' => false,
            'include_user' => true,
            'include_export' => false,
            'team_users' => $teamUsers,
            'timezone' => $this->getDateTimeFactory()->getTimezone()->getName(),
        ]);
    }
}
