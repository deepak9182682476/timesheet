<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Configuration\SystemConfiguration;
use App\Entity\MetaTableTypeInterface;
use App\Entity\Phase;
use App\Entity\Task;
use App\Entity\Timesheet;
use App\EventSubscriber\TimesheetStatusSubscriber;
use App\Event\TimesheetDuplicatePostEvent;
use App\Event\TimesheetDuplicatePreEvent;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use App\Export\ServiceExport;
use App\Form\MultiUpdate\MultiUpdateTable;
use App\Form\MultiUpdate\MultiUpdateTableDTO;
use App\Form\MultiUpdate\TimesheetMultiUpdate;
use App\Form\MultiUpdate\TimesheetMultiUpdateDTO;
use App\Form\TimesheetEditForm;
use App\Form\TimesheetPreCreateForm;
use App\Form\Toolbar\TimesheetToolbarForm;
use App\Repository\Query\BaseQuery;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TagRepository;
use App\Repository\TimesheetRepository;
use App\Timesheet\TimesheetService;
use App\Timesheet\TrackingMode\TrackingModeInterface;
use App\Utils\DataTable;
use App\Utils\PageSetup;
use App\WorkModel\ExportContext;
use App\WorkModel\WorkModelService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class TimesheetAbstractController extends AbstractController
{
    /** the list tab for Agile and Waterfall projects together */
    // private const VIEW_PROJECT = 'project';
    protected const VIEW_PROJECT = 'project';

    public function __construct(
        protected readonly TimesheetRepository $repository,
        protected readonly EventDispatcherInterface $dispatcher,
        protected readonly TimesheetService $service,
        protected readonly SystemConfiguration $configuration,
        protected readonly TagRepository $tagRepository,
        protected readonly WorkModelService $workModels
    ) {
    }

    protected function getTrackingMode(): TrackingModeInterface
    {
        return $this->service->getActiveTrackingMode();
    }

    protected function index(TimesheetQuery $query, Request $request, string $route, string $paginationRoute, string $location): Response
    {
        $form = $this->getToolbarForm($query);
        if ($this->handleSearch($form, $request)) {
            return $this->redirectToRoute($route);
        }

        $canSeeRate = $this->canSeeRate();
        $canSeeUsername = $this->canSeeUsername();

        $this->prepareQuery($query);

        // The list can be narrowed to one kind of work (the tabs above the table): Agile, Waterfall, Pre-sales or
        // Non-project. Each has its own columns, named the way that model names its levels. The choice is kept
        // while the person pages through the list or searches.
        // Only the kinds of work the entries really have are offered. Somebody whose entries are all of one kind
        // (one Agile project, say) is not asked anything: the list shows that kind straight away, with its columns.
        $views = $this->getListViews($query);
        $session = $request->getSession();
        if (\count($views) <= 1) {
            $view = (string) (array_key_first($views) ?? 'all');
            $views = [];
        } else {
            $views = ['all' => 'All'] + $views;
            $view = (string) $request->query->get('view', (string) $session->get('timesheet_view_' . $route, 'all'));
            if (!isset($views[$view])) {
                $view = 'all';
            }
            $session->set('timesheet_view_' . $route, $view);
        }

        // the view narrows what is read, without showing up as a filter of the search form
        $listQuery = $query;
        if ($view !== 'all') {
            $listQuery = clone $query;
            $wanted = $this->getViewProjectIds($view);
            // a project filter of the search form stays in force
            $picked = [];
            foreach ($query->getProjects() as $project) {
                $picked[] = \is_int($project) ? $project : (int) $project->getId();
            }
            if ($picked !== []) {
                $wanted = array_values(array_intersect($wanted, $picked));
            }
            // no project of this kind: an ID that does not exist keeps the list empty
            $listQuery->setProjects($wanted !== [] ? $wanted : [0]);
        }

        // Team Dashboard: on the "Project" tab one project can be picked (see TimesheetTeamController).
        // The choice is kept while paging.
        $projectChoices = $this->getProjectChoices($query, $view);
        $chosenProject = null;
        $chosenProjectId = null;
        if ($projectChoices !== []) {
            $projectKey = 'timesheet_project_' . $route;
            $pick = $request->query->has('project_id') ? $request->query->getInt('project_id') : (int) $session->get($projectKey, 0);
            // if (!isset($projectChoices[$pick])) {
            //     $pick = 0;
            // }
            // there is no "All Projects" any more: without a choice the first project is shown
            if (!isset($projectChoices[$pick])) {
                $pick = (int) array_key_first($projectChoices);
            }
            $session->set($projectKey, $pick);
            if ($pick !== 0) {
                $chosenProject = $projectChoices[$pick];
                $chosenProjectId = $pick;
                $listQuery = clone $query;
                $listQuery->setProjects([$pick]);
            }
        }
        // the Pre-Sales and Non-Project tabs are one project each: the same charts as a picked project
        if ($chosenProject === null && ($single = $this->getViewProject($view)) !== null) {
            [$chosenProjectId, $chosenProject] = $single;
        }
        $summary = $this->getListSummary($listQuery, $view, $chosenProject);

        // With charts above the list, the entries are only shown after "View All Entries" (per tab and project).
        $showEntries = true;
        if ($summary !== null) {
            $entriesKey = 'timesheet_entries_' . $route . '_' . $view . '_' . ($chosenProjectId ?? 0);
            if ($request->query->has('entries')) {
                $session->set($entriesKey, $request->query->getBoolean('entries'));
            }
            $showEntries = (bool) $session->get($entriesKey, false);
        }

        $result = $this->repository->getTimesheetResult($listQuery);
        $metaColumns = $this->findMetaColumns($query, $location);

        // every view remembers its own choice of visible columns
        $table = new DataTable($this->getTableName() . ($view !== 'all' ? '_' . $view : ''), $query);
        $table->setPagination($result->getPagerfanta());
        $table->setSearchForm($form);
        $table->setBatchForm($this->getMultiUpdateActionForm());
        $table->setPaginationRoute($paginationRoute);
        $table->setReloadEvents('kimai.timesheetUpdate kimai.timesheetDelete');

        $table->addColumn('date', ['class' => 'alwaysVisible text-nowrap', 'orderBy' => 'begin']);

        // Begin and End columns are switched off: entries are logged as a date plus a duration
        if (false && $this->canSeeStartEndTime()) { // @phpstan-ignore-line
            $table->addColumn('starttime', ['class' => 'd-none d-sm-table-cell text-center text-nowrap', 'orderBy' => 'begin']);
            $table->addColumn('endtime', ['class' => 'd-none d-sm-table-cell text-center text-nowrap', 'orderBy' => 'end']);
        }

        if ($this->configuration->isBreakTimeEnabled()) {
            $table->addColumn('break', ['class' => 'text-end text-nowrap', 'orderBy' => false]);
        }

        // left-aligned (was right-aligned: 'text-end'), so the duration does not run into the project column
        $table->addColumn('duration', ['class' => 'text-start text-nowrap']);

        if ($canSeeRate) {
            $table->addColumn('hourlyRate', ['class' => 'text-end d-none text-nowrap']);
            $table->addColumn('internalRate', ['class' => 'text-end text-nowrap d-none']);
            $table->addColumn('rate', ['class' => 'text-end text-nowrap d-none']);
        }

        // Customer column is switched off: people pick the project directly
        // $table->addColumn('customer', ['class' => 'd-none d-md-table-cell']);
        // the two built-in projects ("Non-Project Activities", "Pre-Sales") need no project column in their own view
        if (!\in_array($view, [WorkModelService::NON_PROJECT, WorkModelService::PRESALES], true)) {
            $table->addColumn('project', ['class' => 'd-none d-lg-table-cell']);
        }

        // What was picked below the project (custom fields) comes right after it.
        // - "All": one column holding the whole path (Epic > Feature > User Story, Lead > Phase, or the Phase)
        // - a view of one kind of work: one column per level, named the way that model names it
        $levelNames = array_keys(WorkModelService::META_LEVELS);
        $placed = array_merge($levelNames, [Phase::TIMESHEET_META_FIELD]);
        $levelColumns = match ($view) {
            // "Project" holds Agile and Waterfall together: one "Work item" column, like "All"
            // (separate Agile and Waterfall views had one column per level: Epic, Feature, User Story ...)
            WorkModelService::AGILE, WorkModelService::WATERFALL => array_combine($levelNames, \array_slice(WorkModelService::LEVELS[$view], 0, 3)),
            WorkModelService::PRESALES => [$levelNames[0] => 'Lead', Phase::TIMESHEET_META_FIELD => 'Category'],
            WorkModelService::NON_PROJECT => [Phase::TIMESHEET_META_FIELD => 'Category'],
            default => [$levelNames[0] => 'Work item'],
        };
        foreach ($levelColumns as $placedName => $title) {
            foreach ($metaColumns as $metaColumn) {
                if ($metaColumn->getName() === $placedName) {
                    $table->addColumn('mf_' . $metaColumn->getName(), ['title' => $title, 'class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'data' => $metaColumn]);
                }
            }
        }
        $table->addColumn('activity', ['class' => 'd-none d-lg-table-cell']);
        // Task (custom field) is shown right after the activity
        foreach ($metaColumns as $metaColumn) {
            if ($metaColumn->getName() === Task::TIMESHEET_META_FIELD) {
                $table->addColumn('mf_' . $metaColumn->getName(), ['title' => $metaColumn->getLabel(), 'class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'data' => $metaColumn]);
            }
        }
        $placed[] = Task::TIMESHEET_META_FIELD;
        // Status (custom field) is shown right after the activity
        foreach ($metaColumns as $metaColumn) {
            if ($metaColumn->getName() === TimesheetStatusSubscriber::FIELD) {
                $table->addColumn('mf_' . $metaColumn->getName(), ['title' => $metaColumn->getLabel(), 'class' => 'd-none d-md-table-cell', 'orderBy' => false, 'data' => $metaColumn]);
            }
        }
        $table->addColumn('description', ['class' => 'd-none']);
        // Tags are hidden for now: remove the comment marks to bring them back
        // $table->addColumn('tags', ['class' => 'd-none', 'orderBy' => false]);

        foreach ($metaColumns as $metaColumn) {
            if ($metaColumn->getName() === TimesheetStatusSubscriber::FIELD || \in_array($metaColumn->getName(), $placed, true)) {
                continue;
            }
            $table->addColumn('mf_' . $metaColumn->getName(), ['title' => $metaColumn->getLabel(), 'class' => 'd-none', 'orderBy' => false, 'data' => $metaColumn]);
        }

        if ($canSeeUsername) {
            $table->addColumn('username', ['class' => 'd-none d-md-table-cell', 'orderBy' => 'user']);
        }

        // Billable is hidden for now: remove the comment marks to bring it back
        // $table->addColumn('billable', ['class' => 'text-center d-none w-min']);
        $table->addColumn('exported', ['class' => 'text-center d-none w-min']);
        $table->addColumn('actions', ['class' => 'actions']);

        $page = $this->createPageSetup();
        $page->setActionName($this->getActionName());

        return $this->render('timesheet/index.html.twig', [
            'view_rate' => $canSeeRate,
            'page_setup' => $page,
            'dataTable' => $table,
            'action_single' => $this->getActionNameSingle(),
            'stats' => $result->getStatistic(),
            'showSummary' => $this->includeSummary(),
            'metaColumns' => $metaColumns,
            'allowMarkdown' => $this->hasMarkdownSupport(),
            'editRoute' => $this->getEditRoute(),
            'listViews' => $views,
            'listView' => $view,
            'listRoute' => $route,
            'projectModels' => $this->workModels->getProjectModels(),
            'project_choices' => $projectChoices,
            'chosen_project' => $chosenProject,
            'chosen_project_id' => $chosenProjectId,
            'show_entries' => $showEntries,
            'summary' => $summary,
        ]);
    }

    /**
     * The projects that can be picked on a tab of the list, keyed by ID (none by default).
     *
     * @return array<int, string> project names keyed by ID
     */
    protected function getProjectChoices(TimesheetQuery $query, string $view): array
    {
        return [];
    }

    /**
     * The one project a tab stands for, as [ID, name] (none by default), see TimesheetTeamController.
     *
     * @return array{0: int, 1: string}|null
     */
    protected function getViewProject(string $view): ?array
    {
        return null;
    }

    /**
     * Charts shown above the list (none by default), see TimesheetTeamController.
     *
     * @return array<string, mixed>|null
     */
    protected function getListSummary(TimesheetQuery $listQuery, string $view, ?string $project): ?array
    {
        return null;
    }

    /**
     * The kinds of work the entries of this list have, and what their tabs are called. The list of one
     * person looks at that person's entries, the team list at everybody's.
     *
     * @return array<string, string>
     */
    protected function getListViews(TimesheetQuery $query): array
    {
        // Agile and Waterfall are both projects: one "Project" tab for the two
        // (earlier: separate tabs 'Agile' and 'Waterfall')
        $names = [
            WorkModelService::AGILE => 'Project',
            WorkModelService::WATERFALL => 'Project',
            WorkModelService::PRESALES => 'Pre-Sales',
            WorkModelService::NON_PROJECT => 'Non-Project',
        ];
        $keys = [
            WorkModelService::AGILE => self::VIEW_PROJECT,
            WorkModelService::WATERFALL => self::VIEW_PROJECT,
            WorkModelService::PRESALES => WorkModelService::PRESALES,
            WorkModelService::NON_PROJECT => WorkModelService::NON_PROJECT,
        ];

        try {
            $used = $this->workModels->getModelsInUse($query->getUser());
        } catch (\Exception) {
            return [];
        }

        $views = [];
        foreach ($used as $model) {
            $views[$keys[$model]] = $names[$model];
        }

        return $views;
    }

    /**
     * The projects one tab of the list shows: "Project" is every Agile and Waterfall project.
     *
     * @return array<int>
     */
    protected function getViewProjectIds(string $view): array
    {
        $models = $view === self::VIEW_PROJECT ? [WorkModelService::AGILE, WorkModelService::WATERFALL] : [$view];
        $ids = [];
        foreach ($this->workModels->getProjectModels() as $projectId => $model) {
            if (\in_array($model, $models, true)) {
                $ids[] = (int) $projectId;
            }
        }

        return $ids;
    }

    /**
     * @param TimesheetQuery $query
     * @param string $location
     * @return MetaTableTypeInterface[]
     */
    protected function findMetaColumns(TimesheetQuery $query, string $location): array
    {
        $event = new TimesheetMetaDisplayEvent($query, $location);
        $this->dispatcher->dispatch($event);

        return $event->getFields();
    }

    protected function edit(Timesheet $entry, Request $request): Response
    {
        $event = new TimesheetMetaDefinitionEvent($entry);
        $this->dispatcher->dispatch($event);

        $editForm = $this->getEditForm($entry);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            try {
                $this->service->saveTimesheet($entry);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute($this->getTimesheetRoute());
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('timesheet/edit.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'route_back' => $this->getTimesheetRoute(),
            'timesheet' => $entry,
            'form' => $editForm->createView(),
            'template' => $this->getTrackingMode()->getEditTemplate(),
        ]);
    }

    protected function create(Request $request): Response
    {
        $entry = $this->service->createNewTimesheet($this->getUser(), $request);

        $preForm = $this->createFormForGetRequest(TimesheetPreCreateForm::class, $entry, [
            'include_user' => $this->includeUserInForms('create'),
        ]);
        $preForm->submit($request->query->all(), false);

        $createForm = $this->getCreateForm($entry);
        $createForm->handleRequest($request);

        if ($createForm->isSubmitted() && $createForm->isValid()) {
            try {
                $this->service->saveTimesheet($entry);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute($this->getTimesheetRoute());
            } catch (\Exception $ex) {
                $this->handleFormUpdateException($ex, $createForm);
            }
        }

        return $this->render('timesheet/edit.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'route_back' => $this->getTimesheetRoute(),
            'timesheet' => $entry,
            'form' => $createForm->createView(),
            'template' => $this->getTrackingMode()->getEditTemplate(),
        ]);
    }

    protected function duplicate(Timesheet $timesheet, Request $request): Response
    {
        $copyTimesheet = clone $timesheet;
        $copyTimesheet->resetRates();

        $event = new TimesheetMetaDefinitionEvent($copyTimesheet);
        $this->dispatcher->dispatch($event);

        $form = $this->getDuplicateForm($copyTimesheet, $timesheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->dispatcher->dispatch(new TimesheetDuplicatePreEvent($copyTimesheet, $timesheet));
                $this->service->saveTimesheet($copyTimesheet);
                $this->dispatcher->dispatch(new TimesheetDuplicatePostEvent($copyTimesheet, $timesheet));
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute($this->getTimesheetRoute());
            } catch (\Exception $ex) {
                $this->handleFormUpdateException($ex, $form);
            }
        }

        return $this->render('timesheet/edit.html.twig', [
            'timesheet' => $copyTimesheet,
            'form' => $form->createView(),
            'template' => $this->getTrackingMode()->getEditTemplate(),
        ]);
    }

    protected function export(string $type, Request $request, ServiceExport $serviceExport): Response
    {
        $exporter = $serviceExport->getTimesheetExporterById($type);

        if (null === $exporter) {
            throw $this->createNotFoundException();
        }

        $query = $this->createDefaultQuery();
        $query->setOrder(BaseQuery::ORDER_ASC);

        $form = $this->getToolbarForm($query);
        $request->query->set('performSearch', true);

        if ($this->handleSearch($form, $request)) {
            return $this->redirectToRoute($this->getTimesheetRoute());
        }

        $this->prepareQuery($query);

        // make sure that we use the "expected time range"
        if (null !== $query->getBegin()) {
            $query->getBegin()->setTime(0, 0, 0);
        }
        if (null !== $query->getEnd()) {
            $query->getEnd()->setTime(23, 59, 59);
        }

        // the export follows the tab that is open in the list (Agile, Waterfall ...): only that kind of work
        $listQuery = $query;
        $route = $this->getTimesheetRoute();
        $views = $this->getListViews($query);
        $view = \count($views) > 1 ? (string) $request->getSession()->get('timesheet_view_' . $route, 'all') : 'all';
        if (isset($views[$view])) {
            $listQuery = clone $query;
            $wanted = $this->getViewProjectIds($view);
            $picked = [];
            foreach ($query->getProjects() as $project) {
                $picked[] = \is_int($project) ? $project : (int) $project->getId();
            }
            if ($picked !== []) {
                $wanted = array_values(array_intersect($wanted, $picked));
            }
            $listQuery->setProjects($wanted !== [] ? $wanted : [0]);
        }

        $entries = $this->repository->getTimesheetResult($listQuery);
        $results = $entries->getResults();

        // the columns follow the kind of work that is exported (see WorkModelService::describeExport)
        ExportContext::set($this->workModels->describeExport($results));

        $oldMaxExecTime = \ini_get('max_execution_time');
        ini_set('max_execution_time', $this->configuration->getExportTimeout());

        $response = $exporter->render($results, $query);

        ini_set('max_execution_time', $oldMaxExecTime);

        return $response;
    }

    protected function multiUpdate(Request $request): Response
    {
        $dto = new TimesheetMultiUpdateDTO();

        // initial request from the listing posts a different form
        $form = $this->getMultiUpdateActionForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($data instanceof MultiUpdateTableDTO) {
                $dto->setEntities($data->getEntities());
            }
        }

        // using a new timesheet to make sure we ONLY use meta-fields which are registered via events
        $fake = new Timesheet();
        $event = new TimesheetMetaDefinitionEvent($fake);
        $this->dispatcher->dispatch($event);

        foreach ($fake->getMetaFields() as $field) {
            $dto->setMetaField(clone $field);
        }

        $form = $this->getMultiUpdateForm($dto);
        $form->handleRequest($request);

        // remove all, which are not allowed to be edited
        $timesheets = [];
        $disallowed = 0;
        /** @var Timesheet $timesheet */
        foreach ($dto->getEntities() as $timesheet) {
            if (!$this->isGranted('edit', $timesheet)) {
                $disallowed++;
                continue;
            }
            $timesheets[] = $timesheet;
        }

        if ($disallowed > 0) {
            $this->flashWarning(\sprintf('You are missing the permission to edit %s timesheets', $disallowed));
        }

        $dto->setEntities($timesheets);

        if (\count($timesheets) === 0) {
            return $this->redirectToRoute($this->getTimesheetRoute());
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $execute = false;
            /** @var Timesheet $timesheet */
            foreach ($timesheets as $timesheet) {
                if ($dto->isReplaceDescription()) {
                    $timesheet->setDescription($dto->getDescription());
                    $execute = true;
                } elseif($dto->getDescription() !== null && $dto->getDescription() !== '') {
                    $timesheet->setDescription($timesheet->getDescription() . PHP_EOL . $dto->getDescription());
                    $execute = true;
                }
                if ($dto->isReplaceTags()) {
                    foreach ($timesheet->getTags() as $tag) {
                        $timesheet->removeTag($tag);
                    }
                    $execute = true;
                }
                foreach ($dto->getTags() as $tag) {
                    $timesheet->addTag($tag);
                    $execute = true;
                }
                if (null !== $dto->getActivity()) {
                    $timesheet->setActivity($dto->getActivity());
                    $execute = true;
                }
                if (null !== $dto->getProject()) {
                    $timesheet->setProject($dto->getProject());
                    $execute = true;
                }
                if (null !== $dto->getUser()) {
                    $timesheet->setUser($dto->getUser());
                    $execute = true;
                }
                if (null !== $dto->isExported()) {
                    $timesheet->setExported($dto->isExported());
                    $execute = true;
                }
                if (null !== $dto->isBillable()) {
                    $timesheet->setBillable($dto->isBillable());
                    $execute = true;
                }

                if ($dto->isRecalculateRates()) {
                    $timesheet->resetRates();
                    $execute = true;
                } elseif (null !== $dto->getFixedRate()) {
                    $timesheet->setFixedRate($dto->getFixedRate());
                    $timesheet->setHourlyRate(null);
                    $timesheet->setInternalRate(null);
                    $execute = true;
                } elseif (null !== $dto->getHourlyRate()) {
                    $timesheet->setFixedRate(null);
                    $timesheet->setInternalRate(null);
                    $timesheet->setHourlyRate($dto->getHourlyRate());
                    $execute = true;
                }

                foreach ($dto->getUpdateMeta() as $metaName) {
                    if (null !== ($metaField = $dto->getMetaField($metaName))) {
                        if (null !== ($timesheetMeta = $timesheet->getMetaField($metaName))) {
                            $timesheetMeta->setValue($metaField->getValue());
                        } else {
                            $timesheet->setMetaField(clone $metaField);
                        }
                        $execute = true;
                    }
                }
            }

            if ($execute) {
                try {
                    $this->service->updateMultipleTimesheets($timesheets);
                    $this->flashSuccess('action.update.success');

                    return $this->redirectToRoute($this->getTimesheetRoute());
                } catch (\Exception $ex) {
                    $this->flashUpdateException($ex);
                }
            } else {
                $this->flashSuccess(\sprintf('No changes for %s entries detected.', \count($timesheets)));

                return $this->redirectToRoute($this->getTimesheetRoute());
            }
        }

        return $this->render('timesheet/multi-update.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'form' => $form->createView(),
            'dto' => $dto,
            'back' => $this->getTimesheetRoute(),
        ]);
    }

    protected function multiDelete(Request $request): Response
    {
        $form = $this->getMultiUpdateActionForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dto = $form->getData();
            $timesheets = [];
            /** @var Timesheet $timesheet */
            foreach ($dto->getEntities() as $timesheet) {
                if (!$this->isGranted('delete', $timesheet)) {
                    continue;
                }
                $timesheets[] = $timesheet;
            }
            $dto->setEntities($timesheets);

            try {
                $this->service->deleteMultipleTimesheets($dto->getEntities());
                $this->flashSuccess('action.delete.success');
            } catch (\Exception $ex) {
                $this->flashDeleteException($ex);
            }
        }

        return $this->redirectToRoute($this->getTimesheetRoute());
    }

    protected function prepareQuery(TimesheetQuery $query): void
    {
        $query->setUser($this->getUser());
    }

    protected function getMultiUpdateForm(TimesheetMultiUpdateDTO $multiUpdate): FormInterface
    {
        return  $this->createForm(TimesheetMultiUpdate::class, $multiUpdate, [
            'action' => $this->generateUrl($this->getMultiUpdateRoute(), []),
            'method' => 'POST',
            'include_exported' => $this->isGranted($this->getPermissionEditExport()),
            'include_billable' => $this->isGranted($this->getPermissionEditBillable()),
            'include_rate' => $this->isGranted($this->getPermissionEditRate()),
            'include_user' => $this->includeUserInForms('multi'),
        ]);
    }

    protected function getMultiUpdateActionForm(): FormInterface
    {
        $dto = new MultiUpdateTableDTO();

        $dto->addUpdate($this->generateUrl($this->getMultiUpdateRoute()));
        $dto->addDelete($this->generateUrl($this->getMultiDeleteRoute()));

        return $this->createForm(MultiUpdateTable::class, $dto, [
            'action' => $this->generateUrl($this->getTimesheetRoute()),
            'repository' => $this->repository,
            'method' => 'POST',
        ]);
    }

    /**
     * @param class-string<FormTypeInterface> $formClass
     */
    protected function generateCreateForm(Timesheet $entry, string $formClass, string $action): FormInterface
    {
        $mode = $this->getTrackingMode();

        return $this->createForm($formClass, $entry, [
            'action' => $action,
            'include_rate' => $this->isGranted('edit_rate', $entry),
            'include_exported' => $this->isGranted('edit_export', $entry),
            'include_billable' => $this->isGranted('edit_billable', $entry),
            'include_user' => $this->includeUserInForms('create'),
            'allow_begin_datetime' => $mode->canEditBegin(),
            'allow_end_datetime' => $mode->canEditEnd(),
            'allow_duration' => $mode->canEditDuration(),
            'duration_minutes' => $this->configuration->getTimesheetIncrementDuration(),
            'timezone' => $this->getDateTimeFactory()->getTimezone(),
            'customer' => true,
            'create_activity' => $this->isGranted('create_activity'),
        ]);
    }

    private function getEditForm(Timesheet $entry): FormInterface
    {
        $mode = $this->getTrackingMode();

        return $this->createForm($this->getEditFormClassName(), $entry, [
            'action' => $this->generateUrl($this->getEditRoute(), [
                'id' => $entry->getId(),
            ]),
            'include_rate' => $this->isGranted('edit_rate', $entry),
            'include_exported' => $this->isGranted('edit_export', $entry),
            'include_billable' => $this->isGranted('edit_billable', $entry),
            'include_user' => $this->includeUserInForms('edit'),
            'create_activity' => $this->isGranted('create_activity'),
            'allow_begin_datetime' => $mode->canEditBegin(),
            'allow_end_datetime' => $mode->canEditEnd(),
            'allow_duration' => $mode->canEditDuration(),
            'duration_minutes' => $this->configuration->getTimesheetIncrementDuration(),
            'timezone' => $this->getDateTimeFactory()->getTimezone(),
            'customer' => true,
        ]);
    }

    protected function getToolbarForm(TimesheetQuery $query): FormInterface
    {
        return $this->createSearchForm(TimesheetToolbarForm::class, $query, [
            'action' => $this->generateUrl($this->getTimesheetRoute(), [
                'page' => $query->getPage(),
            ]),
            'timezone' => $this->getDateTimeFactory()->getTimezone()->getName(),
            'include_user' => $this->includeUserInForms('toolbar'),
        ]);
    }

    protected function getPermissionEditExport(): string
    {
        return 'edit_export_own_timesheet';
    }

    protected function getPermissionEditBillable(): string
    {
        return 'edit_billable_own_timesheet';
    }

    protected function getPermissionEditRate(): string
    {
        return 'edit_rate_own_timesheet';
    }

    /**
     * @return class-string<FormTypeInterface>
     */
    protected function getEditFormClassName(): string
    {
        return TimesheetEditForm::class;
    }

    protected function includeSummary(): bool
    {
        return (bool) $this->getUser()->getPreferenceValue('daily_stats', false, false);
    }

    protected function includeUserInForms(string $formName): bool
    {
        return false;
    }

    protected function getTimesheetRoute(): string
    {
        return 'timesheet';
    }

    protected function getEditRoute(): string
    {
        return 'timesheet_edit';
    }

    protected function getMultiUpdateRoute(): string
    {
        return 'timesheet_multi_update';
    }

    protected function getMultiDeleteRoute(): string
    {
        return 'timesheet_multi_delete';
    }

    protected function canSeeStartEndTime(): bool
    {
        return $this->getTrackingMode()->canSeeBeginAndEndTimes();
    }

    protected function getQueryNamePrefix(): string
    {
        return 'MyTimes';
    }

    protected function createDefaultQuery(string $suffix = 'Listing'): TimesheetQuery
    {
        $query = new TimesheetQuery();
        $query->setName($this->getQueryNamePrefix() . $suffix);

        return $query;
    }

    protected function canSeeRate(): bool
    {
        return $this->isGranted('view_rate_own_timesheet');
    }

    protected function canSeeUsername(): bool
    {
        return false;
    }

    protected function hasMarkdownSupport(): bool
    {
        return true;
    }

    protected function getTableName(): string
    {
        return 'timesheet';
    }

    protected function getActionName(): string
    {
        return 'timesheets';
    }

    protected function getActionNameSingle(): string
    {
        return 'timesheet';
    }

    protected function createPageSetup(): PageSetup
    {
        $page = new PageSetup('Log Time');
        $page->setHelp('timesheet.html');

        return $page;
    }

    abstract protected function getDuplicateForm(Timesheet $entry, Timesheet $original): FormInterface;

    abstract protected function getCreateForm(Timesheet $entry): FormInterface;
}
