<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Attendance\AttendanceService;
use App\Attendance\AttendanceUpload;
use App\Entity\WfhRequest;
use App\TeamEvent\TeamEventService;
use App\Utils\PageSetup;
use App\WorkModel\WorkModelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Attendance: every day an administrator enters who was physically present in the office (Yes / No), on the page
 * or with an Excel upload. The time entries of the people follow it (see AttendanceService::getEntryStates).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AttendanceController extends AbstractController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceUpload $upload,
        private readonly TeamEventService $events,
        private readonly WorkModelService $models,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/attendance/', name: 'attendance', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAdmin();
        $day = $this->readDay((string) $request->get('date', ''));
        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('attendance', (string) $request->request->get('_token'))) {
                $this->flashError('action.csrf.error');

                return $this->redirectToRoute('attendance', ['date' => $day->format('Y-m-d')]);
            }

            if ($request->request->get('action') === 'upload') {
                $result = $this->handleUpload($request);
            } else {
                $values = [];
                foreach ((array) $request->request->all('present') as $userId => $value) {
                    $values[(int) $userId] = match ((string) $value) {
                        'yes' => true,
                        'no' => false,
                        default => null,
                    };
                }
                $changed = $this->attendance->saveDay($day, $values, $this->getUser());
                $this->flashSuccess($changed === 1 ? 'Attendance saved (1 change).' : 'Attendance saved (' . $changed . ' changes).');

                return $this->redirectToRoute('attendance', ['date' => $day->format('Y-m-d')]);
            }
        }

        $people = $this->attendance->getPeople();

        // the WFH requests (not rejected) of the day, to show next to the names: user ID => approved / pending
        $wfh = [];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, status FROM kimai2_wfh_requests WHERE status <> ? AND start_date <= ? AND end_date >= ?',
            [WfhRequest::STATUS_REJECTED, $day->format('Y-m-d'), $day->format('Y-m-d')]
        );
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            if (!isset($wfh[$id]) || $row['status'] === WfhRequest::STATUS_APPROVED) {
                $wfh[$id] = (string) $row['status'];
            }
        }

        return $this->render('attendance/index.html.twig', [
            'page_setup' => new PageSetup('Attendance'),
            'day' => $day,
            'previous' => $day->modify('-1 day'),
            'next' => $day->modify('+1 day'),
            'people' => $people,
            'entered' => $this->attendance->getDay($day),
            'wfh' => $wfh,
            'result' => $result,
            'max_days' => AttendanceUpload::MAX_DAYS,
            'tracking_from' => AttendanceService::TRACKING_FROM,
        ]);
    }

    #[Route(path: '/attendance/template', name: 'attendance_template', methods: ['GET'])]
    public function template(Request $request): Response
    {
        $this->denyUnlessAdmin();
        $from = $this->readDay((string) $request->query->get('from', ''));
        $to = $this->readDay((string) $request->query->get('to', $from->format('Y-m-d')));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diff($to)->days + 1 > AttendanceUpload::MAX_DAYS) {
            $to = $from->modify('+' . (AttendanceUpload::MAX_DAYS - 1) . ' days');
        }

        $response = new BinaryFileResponse($this->upload->createTemplate($from, $to));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $name = 'attendance-' . $from->format('Y-m-d') . ($to > $from ? '-to-' . $to->format('Y-m-d') : '') . '.xlsx';
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * The attendance of a period as an Excel file: this week, this month, or the dates picked.
     */
    #[Route(path: '/attendance/export', name: 'attendance_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $this->denyUnlessAdmin();
        $today = new \DateTimeImmutable('today');
        $period = (string) $request->query->get('period', '');
        if ($period === 'week') {
            $from = $today->modify('monday this week');
            $to = $from->modify('+6 days');
        } elseif ($period === 'month') {
            $from = $today->modify('first day of this month');
            $to = $today->modify('last day of this month');
        } else {
            $from = $this->readDay((string) $request->query->get('from', ''));
            $to = $this->readDay((string) $request->query->get('to', $from->format('Y-m-d')));
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diff($to)->days + 1 > AttendanceUpload::MAX_EXPORT_DAYS) {
            $to = $from->modify('+' . (AttendanceUpload::MAX_EXPORT_DAYS - 1) . ' days');
        }

        $response = new BinaryFileResponse($this->upload->createExport($from, $to));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'attendance-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.xlsx');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * How the time entries with these IDs are shown (light cement background, thick border for WFH waiting).
     * Only the entries of people the asking person can see.
     */
    #[Route(path: '/attendance/entry-states', name: 'attendance_entry_states', methods: ['GET'])]
    public function entryStates(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $ids = \array_slice(array_filter(array_map('intval', explode(',', (string) $request->query->get('ids', '')))), 0, 1000);
        $people = null;
        if (!$this->events->isAdmin($user)) {
            $people = array_map(static fn ($person) => (int) $person->getId(), $this->models->getTeamPeople($user));
        }

        try {
            $states = $this->attendance->getEntryStates($ids, $people);
        } catch (\Exception) {
            $states = [];
        }

        return new JsonResponse((object) $states);
    }

    /**
     * @return array{saved: int, rows: int, errors: array<int, string>, file: string}
     */
    private function handleUpload(Request $request): array
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return ['saved' => 0, 'rows' => 0, 'errors' => [1 => 'Please choose the Excel file to upload.'], 'file' => ''];
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return ['saved' => 0, 'rows' => 0, 'errors' => [1 => 'Only Excel files (.xlsx) can be uploaded. Please start from the template.'], 'file' => $file->getClientOriginalName()];
        }
        try {
            $result = $this->upload->import($file->getPathname(), $this->getUser());
        } catch (\Throwable $ex) {
            $result = ['saved' => 0, 'rows' => 0, 'errors' => [1 => 'The file could not be read: ' . $ex->getMessage()]];
        }
        $result['file'] = $file->getClientOriginalName();

        return $result;
    }

    private function readDay(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date !== false ? $date : new \DateTimeImmutable('today');
    }

    private function denyUnlessAdmin(): void
    {
        // if (!$this->events->isAdmin($this->getUser())) {
        // only the system administrator enters the attendance
        if (!$this->getUser()->isSuperAdmin()) {
            throw $this->createAccessDeniedException('Only administrators enter the attendance');
        }
    }
}
