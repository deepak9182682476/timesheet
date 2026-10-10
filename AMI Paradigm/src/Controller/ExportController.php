<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Configuration\SystemConfiguration;
use App\Entity\ExportableItem;
use App\Entity\ExportTemplate;
use App\Export\Base\DispositionInlineInterface;
use App\Export\ServiceExport;
use App\Export\TooManyItemsExportException;
use App\Form\ExportTemplateSpreadsheetForm;
use App\Form\Toolbar\ExportToolbarForm;
use App\Repository\ExportTemplateRepository;
use App\Repository\Query\ExportQuery;
use App\Utils\PageSetup;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller used to export timesheet data.
 */
#[Route(path: '/export')]
#[IsGranted('create_export')]
final class ExportController extends AbstractController
{
    /** the request comes from the export box of Team Dashboard (see readScope) */
    private bool $teamScope = false;

    public function __construct(private readonly ServiceExport $export, private readonly \App\WorkModel\WorkModelService $workModels)
    {
    }

    /**
     * Team Dashboard's export box sends scope=team: it exports the dashboard's whole team. The flag is taken
     * out of the request so it does not reach the search form.
     */
    private function readScope(Request $request): void
    {
        $scope = $request->query->get('scope') ?? $request->request->get('scope');
        $this->teamScope = $scope === 'team' && $this->isGranted('view_other_timesheet');
        $request->query->remove('scope');
        $request->request->remove('scope');
    }

    #[Route(path: '/', name: 'export', methods: ['GET'])]
    public function indexAction(Request $request): Response
    {
        $query = $this->getDefaultQuery();

        $showPreview = false;
        $tooManyResults = false;
        $maxItemsPreview = 500;
        $entries = [];

        $form = $this->getToolbarForm($query, 'GET');
        if ($this->handleSearch($form, $request)) {
            return $this->redirectToRoute('export');
        }

        $byCustomer = [];

        if ($form->isValid() && ($query->hasBookmark() || $request->query->has('performSearch'))) {
            try {
                $showPreview = true;
                $entries = $this->getEntries($query);
                foreach ($entries as $entry) {
                    $cid = $entry->getProject()->getCustomer()->getId();
                    if (!isset($byCustomer[$cid])) {
                        $byCustomer[$cid] = [
                            'customer' => $entry->getProject()->getCustomer(),
                            'rate' => 0,
                            'internalRate' => 0,
                            'duration' => 0,
                        ];
                    }
                    $byCustomer[$cid]['rate'] += $entry->getRate();
                    $byCustomer[$cid]['internalRate'] += $entry->getInternalRate() ?? 0.0;
                    $byCustomer[$cid]['duration'] += $entry->getDuration() ?? 0;
                }
            } catch (TooManyItemsExportException $ex) {
                $tooManyResults = true;
                $showPreview = false;
                $entries = [];
                $this->logException($ex);
            }
        }

        $page = new PageSetup('export');
        $page->setHelp('export.html');

        $buttons = [];
        foreach ($this->export->getRenderer() as $renderer) {
            if (method_exists($renderer, 'getType')) {
                $class = $renderer->getType();
            } else {
                // TODO remove me with 3.0
                $class = \get_class($renderer);
                $pos = strrpos($class, '\\');
                if ($pos !== false) {
                    $class = substr($class, $pos + 1);
                }
                $class = strtolower(str_replace('Renderer', '', $class));
            }
            $buttons[$class][$renderer->getId()] = [
                'title' => $renderer->getTitle(),
                'internal' => method_exists($renderer, 'isInternal') ? $renderer->isInternal() : false,
            ];
        }

        if ($this->isGranted('view_other_timesheet')) {
            $showRates = $this->isGranted('view_rate_other_timesheet');
        } else {
            $showRates = $this->isGranted('view_rate_own_timesheet');
        }

        return $this->render('export/index.html.twig', [
            'page_setup' => $page,
            'too_many' => $tooManyResults,
            'by_customer' => $byCustomer,
            'query' => $query,
            'entries' => $entries,
            'form' => $form->createView(),
            'buttons' => $buttons,
            'preview_limit' => $maxItemsPreview,
            'preview_show' => $showPreview,
            'show_rates' => $showRates,
            // which kind of work the found entries belong to: decides the columns of the preview
            'work' => $this->workModels->describeExport($entries),
            // who is mapped to which project: the User box only offers the people of the chosen projects
            // (the person who is logged in is always offered, they export their own projects)
            'project_users' => array_map(fn (array $ids) => array_values(array_unique(array_merge($ids, [(int) $this->getUser()->getId()]))), $this->workModels->getMappedUserIds()),
        ]);
    }

    /**
     * How many entries an export would hold and their hours, for the export box of Team Log Time:
     * it asks this first and then offers CSV, PDF, Excel and Print.
     */
    #[Route(path: '/count', name: 'export_count', methods: ['GET'])]
    public function count(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->readScope($request);
        $query = $this->getDefaultQuery();
        $form = $this->getToolbarForm($query, 'GET');
        $form->submit($request->query->all(), false);
        if (!$form->isValid()) {
            $problems = [];
            foreach ($form->getErrors(true) as $error) {
                $problems[] = $error->getMessage();
            }

            return $this->json(['error' => $problems !== [] ? implode(' ', array_unique($problems)) : 'Please check the filters.'], 400);
        }

        try {
            $entries = $this->getEntries($query);
        } catch (TooManyItemsExportException) {
            return $this->json(['error' => 'Too many entries for one export. Please choose a shorter time range.'], 400);
        }

        $seconds = 0;
        foreach ($entries as $entry) {
            $seconds += $entry->getDuration() ?? 0;
        }

        return $this->json(['count' => \count($entries), 'seconds' => $seconds]);
    }

    #[Route(path: '/data', name: 'export_data', methods: ['POST'])]
    public function export(Request $request, SystemConfiguration $systemConfiguration): Response
    {
        $token = $request->request->get('_token');
        $token = \is_string($token) ? $token : null;

        if (!$this->isCsrfTokenValid('export.data', $token)) {
            throw $this->createAccessDeniedException('Invalid security token for export.');
        }

        // prevent the token from becoming part of the search query
        $request->request->remove('_token');
        $this->readScope($request);

        $query = $this->getDefaultQuery();

        $form = $this->getToolbarForm($query, 'POST');
        $form->handleRequest($request);

        // shouldn't happen in regular use-cases, do not show form errors
        if ($form->isSubmitted() && !$form->isValid()) {
            throw $this->createAccessDeniedException('Export form validation failed.');
        }

        $type = $query->getRenderer();
        if (null === $type) {
            throw $this->createNotFoundException('Missing export renderer');
        }

        $renderer = $this->export->getRendererById($type);

        if (null === $renderer) {
            throw $this->createNotFoundException('Unknown export renderer');
        }

        $oldMaxExecTime = \ini_get('max_execution_time');
        ini_set('max_execution_time', $systemConfiguration->getExportTimeout());

        // display file inline if supported and `markAsExported` is not set
        if ($renderer instanceof DispositionInlineInterface && !$query->isMarkAsExported()) {
            $renderer->setDispositionInline(true);
        }

        $entries = $this->getEntries($query);
        // the columns follow the kind of work that is exported (see WorkModelService::describeExport)
        \App\WorkModel\ExportContext::set($this->workModels->describeExport($entries));
        $response = $renderer->render($entries, $query);

        if ($query->isMarkAsExported()) {
            $this->export->setExported($entries);
        }

        ini_set('max_execution_time', $oldMaxExecTime);

        return $response;
    }

    private function getDefaultQuery(): ExportQuery
    {
        $begin = $this->getDateTimeFactory()->getStartOfMonth();
        $end = $this->getDateTimeFactory()->getEndOfMonth();

        $query = new ExportQuery();
        $query->setBegin($begin);
        $query->setEnd($end);
        $query->setCurrentUser($this->getUser());

        return $query;
    }

    /**
     * @return ExportableItem[]
     * @throws TooManyItemsExportException
     */
    private function getEntries(ExportQuery $query): array
    {
        if (null !== $query->getBegin()) {
            $query->getBegin()->setTime(0, 0, 0);
        }
        if (null !== $query->getEnd()) {
            $query->getEnd()->setTime(23, 59, 59);
        }

        // People who are not admins export their own people only: without a chosen user
        // the export holds the whole team of the person who is logged in, never everybody.
        $team = $this->getTeamUsers();
        if ($team !== null && \count($query->getUsers()) === 0) {
            foreach ($team as $member) {
                $query->addUser($member);
            }
        }

        return $this->export->getExportItems($query);
    }

    /**
     * The people whose entries the logged-in person may export: the people who report to them
     * (directly or further down) and themselves. Admins get null, which means everybody.
     *
     * @return array<\App\Entity\User>|null
     */
    private function getTeamUsers(): ?array
    {
        $user = $this->getUser();
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return null;
        }

        // the export box of Team Dashboard (scope=team): the whole team, the same people as the dashboard
        // (WorkModelService::getTeamPeople); the Export page itself: oneself and the people who report to one
        $team = $this->teamScope ? $this->workModels->getTeamPeople($user) : $this->workModels->getAssignableUsers($user);

        return $team !== [] ? $team : [$user];
    }

    /**
     * @return FormInterface<ExportQuery>
     */
    private function getToolbarForm(ExportQuery $query, string $method): FormInterface
    {
        return $this->createSearchForm(ExportToolbarForm::class, $query, [
            'action' => $this->generateUrl('export', []),
            'include_user' => $this->isGranted('view_other_timesheet'),
            'include_export' => $this->isGranted('edit_export_other_timesheet'),
            'team_users' => $this->getTeamUsers(),
            'method' => $method,
            'timezone' => $this->getDateTimeFactory()->getTimezone()->getName(),
            'attr' => [
                'id' => 'export-form'
            ]
        ]);
    }

    #[Route(path: '/template-create', name: 'export_template_create', methods: ['GET', 'POST'])]
    #[IsGranted('create_export_template')]
    public function createExportTemplate(Request $request, ExportTemplateRepository $repository): Response
    {
        return $this->editExportForm($this->generateUrl('export_template_create'), $request, $repository, new ExportTemplate());
    }

    #[Route(path: '/template-edit/{exportTemplate}', name: 'export_template_edit', methods: ['GET', 'POST'])]
    #[IsGranted('create_export_template')]
    public function editExportTemplate(ExportTemplate $exportTemplate, Request $request, ExportTemplateRepository $repository): Response
    {
        return $this->editExportForm($this->generateUrl('export_template_edit', ['exportTemplate' => $exportTemplate->getId()]), $request, $repository, $exportTemplate);
    }

    private function editExportForm(string $url, Request $request, ExportTemplateRepository $repository, ExportTemplate $exportTemplate): Response
    {
        $form = $this->createForm(ExportTemplateSpreadsheetForm::class, $exportTemplate, [
            'action' => $url,
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $repository->saveExportTemplate($exportTemplate);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('export');
            } catch (\Exception $ex) {
                $this->handleFormUpdateException($ex, $form);
            }
        }

        return $this->render('export/template.html.twig', [
            'form' => $form->createView(),
            'template' => $exportTemplate,
        ]);
    }
}
