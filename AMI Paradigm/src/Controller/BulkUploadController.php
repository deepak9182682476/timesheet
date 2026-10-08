<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\BulkUpload\BulkUploadService;
use App\Utils\PageSetup;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk upload of time entries from an Excel file (see BulkUploadService for the rules).
 * For leads, managers and admins only: an employee gets "access denied", also when opening the address directly.
 */
#[Route(path: '/timesheet/bulk-upload')]
#[IsGranted('ROLE_TEAMLEAD')]
final class BulkUploadController extends AbstractController
{
    public function __construct(private readonly BulkUploadService $bulkUpload)
    {
    }

    #[Route(path: '', name: 'timesheet_bulk_upload', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $result = null;
        $fileName = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('timesheet_bulk_upload', (string) $request->request->get('_token'))) {
                $this->flashError('action.csrf.error');

                return $this->redirectToRoute('timesheet_bulk_upload');
            }

            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $result = ['saved' => 0, 'skipped' => 0, 'errors' => [1 => ['Please choose the Excel file to upload.']], 'people' => []];
            } elseif (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                $result = ['saved' => 0, 'skipped' => 0, 'errors' => [1 => ['Only Excel files (.xlsx) can be uploaded. Please start from the template.']], 'people' => []];
            } else {
                $fileName = $file->getClientOriginalName();
                try {
                    $result = $this->bulkUpload->import($user, $file->getPathname());
                } catch (\Throwable $ex) {
                    $result = ['saved' => 0, 'skipped' => 0, 'errors' => [1 => ['The file could not be read: ' . $ex->getMessage()]], 'people' => []];
                }
            }
        }

        $people = $this->bulkUpload->getAllowedUsers($user);
        unset($people[(int) $user->getId()]);

        return $this->render('bulk-upload/index.html.twig', [
            'page_setup' => new PageSetup('Bulk Upload'),
            'result' => $result,
            'file_name' => $fileName,
            'headers' => BulkUploadService::HEADERS,
            'people' => $people,
            'max_rows' => BulkUploadService::MAX_ROWS,
        ]);
    }

    #[Route(path: '/template', name: 'timesheet_bulk_upload_template', methods: ['GET'])]
    public function template(): Response
    {
        $file = $this->bulkUpload->createTemplate($this->getUser());

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'timesheet-bulk-upload-template.xlsx');
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
