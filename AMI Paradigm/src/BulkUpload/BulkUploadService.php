<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\BulkUpload;

use App\Entity\Activity;
use App\Entity\ActivityTask;
use App\Entity\Phase;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Event\TimesheetMetaDefinitionEvent;
use App\EventSubscriber\TimesheetStatusSubscriber;
use App\Task\TaskService;
use App\Timesheet\TimesheetService;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Validator\ValidationFailedException;

/**
 * Bulk upload of time entries from an Excel file.
 *
 * One row is one entry: date, employee, project > phase > activity > task, hours, status, description.
 * A row without an employee is for the person who uploads. A row for somebody else is accepted only from
 * that person's superiors (the Supervisor chain) or an administrator; the entry then lands in that
 * person's own timesheet. A row with a problem (for example a row for somebody the uploader is not a
 * superior of) is rejected and reported; the other rows are saved.
 */
final class BulkUploadService
{
    public const HEADERS = ['Date', 'Employee', 'Project', 'Phase', 'Activity', 'Task', 'Hours', 'Status', 'Description'];
    /** Rows with this description are the examples of the template: they are skipped */
    public const SAMPLE_MARK = 'Sample row - replace or delete';
    public const MAX_ROWS = 1000;
    private const MAX_HOURS = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskService $tasks,
        private readonly TimesheetService $timesheets,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * The people this user may upload entries for: themselves and everybody below them.
     *
     * @return array<int, User>
     */
    public function getAllowedUsers(User $user): array
    {
        $allowed = [(int) $user->getId() => $user];
        foreach ($this->tasks->getManagedUsers($user) as $id => $managed) {
            $allowed[$id] = $managed;
        }

        return $allowed;
    }

    /**
     * Writes the Excel template (header, two example rows, and a sheet with the allowed values) and returns its path.
     */
    public function createTemplate(User $user): string
    {
        $file = tempnam(sys_get_temp_dir(), 'bulk-upload-') . '.xlsx';
        $bold = (new Style())->setFontBold();

        $writer = new Writer();
        $writer->openToFile($file);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Time entries');
        $widths = [14, 22, 28, 28, 28, 38, 9, 14, 45];
        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues(self::HEADERS, $bold));

        $combinations = $this->getCombinations($user);
        $today = (new \DateTime('now', new \DateTimeZone($user->getTimezone())))->format('Y-m-d');
        $samples = \array_slice(array_values(array_filter($combinations, static fn (array $c) => $c[2] !== '')), 0, 2);
        if ($samples === []) {
            $samples = [['Project name', 'Phase name', 'Activity name', '']];
        }
        foreach ($samples as $index => $sample) {
            $writer->addRow(Row::fromValues([$today, $user->getUserIdentifier(), $sample[0], $sample[1], $sample[2], $sample[3], $index === 0 ? 2 : 1.5, TimesheetStatusSubscriber::IN_PROGRESS, self::SAMPLE_MARK]));
        }

        $help = $writer->addNewSheetAndMakeItCurrent();
        $help->setName('How to fill');
        $help->setColumnWidth(18, 1);
        $help->setColumnWidth(110, 2);
        $writer->addRow(Row::fromValues(['Column', 'What to enter'], $bold));
        foreach ([
            ['Date', 'The day of the work, as YYYY-MM-DD (for example ' . $today . ') or as a normal Excel date.'],
            ['Employee', 'Username or e-mail of the person the entry is for. Leave empty for yourself. Entries for other people are accepted only from their superiors.'],
            ['Project', 'Project name, exactly as in the sheet "Allowed values".'],
            ['Phase', 'A phase of that project.'],
            ['Activity', 'An activity of that phase.'],
            ['Task', 'Optional. A task of that activity.'],
            ['Hours', 'From 0.5 to ' . self::MAX_HOURS . ' in steps of 0.5 (0.5, 1, 1.5, 2 ...).'],
            ['Status', TimesheetStatusSubscriber::IN_PROGRESS . ' or ' . TimesheetStatusSubscriber::COMPLETED . '. Empty means ' . TimesheetStatusSubscriber::IN_PROGRESS . '.'],
            ['Description', 'Optional note. Rows that still say "' . self::SAMPLE_MARK . '" are examples and are skipped.'],
            ['', ''],
            ['Good to know', 'Keep the first row (the column names) as it is. Correct rows are saved; rows with a problem are rejected and listed. Fix those and upload only them again, otherwise the saved rows are doubled.'],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }

        $values = $writer->addNewSheetAndMakeItCurrent();
        $values->setName('Allowed values');
        foreach ([28, 28, 28, 40] as $index => $width) {
            $values->setColumnWidth($width, $index + 1);
        }
        $values->setColumnWidth(4, 5);
        $values->setColumnWidth(24, 6);
        $values->setColumnWidth(28, 7);
        $writer->addRow(Row::fromValues(['Project', 'Phase', 'Activity', 'Task', '', 'Employee (username)', 'Name'], $bold));
        $people = array_values($this->getAllowedUsers($user));
        $lines = max(\count($combinations), \count($people));
        for ($i = 0; $i < $lines; $i++) {
            $combination = $combinations[$i] ?? ['', '', '', ''];
            $person = $people[$i] ?? null;
            $writer->addRow(Row::fromValues([...$combination, '', $person?->getUserIdentifier() ?? '', $person?->getDisplayName() ?? '']));
        }

        $writer->setCurrentSheet($sheet);
        $writer->close();

        return $file;
    }

    /**
     * Reads the file and checks every row. Correct rows are saved, rows with a problem are rejected and reported.
     *
     * @return array{saved: int, skipped: int, errors: array<int, array<string>>, people: array<string, int>}
     */
    public function import(User $uploader, string $path): array
    {
        $result = ['saved' => 0, 'skipped' => 0, 'errors' => [], 'people' => []];

        $rows = $this->readRows($path);
        if ($rows === null) {
            $result['errors'][1] = ['The first row has to hold the column names: ' . implode(', ', self::HEADERS) . '. Please start from the template.'];

            return $result;
        }
        if (\count($rows) > self::MAX_ROWS) {
            $result['errors'][1] = ['The file has more than ' . self::MAX_ROWS . ' rows. Please split it into smaller files.'];

            return $result;
        }

        $allowed = $this->getAllowedUsers($uploader);
        $entries = [];
        $cache = [];

        foreach ($rows as $line => $row) {
            if (trim((string) $row['Description']) === self::SAMPLE_MARK) {
                $result['skipped']++;
                continue;
            }
            $errors = [];
            $timesheet = $this->buildEntry($uploader, $allowed, $row, $errors, $cache);
            if ($timesheet !== null && $errors === []) {
                try {
                    $this->timesheets->validateTimesheet($timesheet);
                } catch (ValidationFailedException $ex) {
                    foreach ($ex->getViolations() as $violation) {
                        $errors[] = (string) $violation->getMessage();
                    }
                }
            }
            if ($errors !== []) {
                $result['errors'][$line] = $errors;
                continue;
            }
            $entries[] = $timesheet;
        }

        // rows with a problem are rejected and reported; the correct rows are saved
        if ($entries === []) {
            if ($result['errors'] === []) {
                $result['errors'][2] = ['The file has no rows to upload.'];
            }

            return $result;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($entries as $timesheet) {
                $this->entityManager->persist($timesheet);
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $ex) {
            $connection->rollBack();
            throw $ex;
        }

        foreach ($entries as $timesheet) {
            $name = $timesheet->getUser()->getDisplayName();
            $result['people'][$name] = ($result['people'][$name] ?? 0) + 1;
        }
        $result['saved'] = \count($entries);

        return $result;
    }

    /**
     * @param array<int, User> $allowed
     * @param array<string, mixed> $row
     * @param array<string> $errors
     * @param array<string, mixed> $cache
     */
    private function buildEntry(User $uploader, array $allowed, array $row, array &$errors, array &$cache): ?Timesheet
    {
        // who
        $employee = trim((string) $row['Employee']);
        $user = $uploader;
        if ($employee !== '') {
            $user = null;
            foreach ($this->entityManager->getRepository(User::class)->findAll() as $candidate) {
                foreach ([$candidate->getUserIdentifier(), $candidate->getEmail(), $candidate->getAlias(), $candidate->getDisplayName()] as $name) {
                    if ($name !== null && $name !== '' && $this->same($name, $employee)) {
                        $user = $candidate;
                        break 2;
                    }
                }
            }
            if ($user === null) {
                $errors[] = 'Employee "' . $employee . '" was not found. Use the username or e-mail.';
            } elseif (!isset($allowed[(int) $user->getId()])) {
                $errors[] = 'Rejected: you cannot upload entries for ' . $user->getDisplayName() . ', only their superiors can.';
                $user = null;
            }
        }

        // when
        $date = $this->parseDate($row['Date']);
        if ($date === null) {
            $errors[] = 'Date "' . $this->text($row['Date']) . '" is not a date. Use YYYY-MM-DD.';
        }

        // how long
        $hours = is_numeric($row['Hours']) ? (float) $row['Hours'] : (is_numeric(str_replace(',', '.', (string) $row['Hours'])) ? (float) str_replace(',', '.', (string) $row['Hours']) : null);
        if ($hours === null || $hours < 0.5 || $hours > self::MAX_HOURS || abs($hours * 2 - round($hours * 2)) > 0.0001) {
            $errors[] = 'Hours "' . $this->text($row['Hours']) . '" has to be between 0.5 and ' . self::MAX_HOURS . ', in steps of 0.5.';
            $hours = null;
        }

        // status
        $status = trim((string) $row['Status']);
        if ($status === '') {
            $status = TimesheetStatusSubscriber::IN_PROGRESS;
        } elseif ($this->same($status, TimesheetStatusSubscriber::IN_PROGRESS)) {
            $status = TimesheetStatusSubscriber::IN_PROGRESS;
        } elseif ($this->same($status, TimesheetStatusSubscriber::COMPLETED)) {
            $status = TimesheetStatusSubscriber::COMPLETED;
        } else {
            $errors[] = 'Status "' . $status . '" has to be "' . TimesheetStatusSubscriber::IN_PROGRESS . '" or "' . TimesheetStatusSubscriber::COMPLETED . '".';
        }

        if ($user === null) {
            return null;
        }

        // project > phase > activity > task
        $project = $this->findProject($user, trim((string) $row['Project']), $errors, $cache);
        $phase = $project !== null ? $this->findPhase($project, trim((string) $row['Phase']), $errors) : null;
        $activity = ($project !== null && $phase !== null) ? $this->findActivity($project, $phase, trim((string) $row['Activity']), $errors) : null;
        $task = null;
        $taskName = trim((string) $row['Task']);
        if ($taskName !== '' && $activity !== null && $project !== null) {
            $task = $this->findTask($user, $project, $activity, $taskName, $errors);
        }

        if ($errors !== [] || $project === null || $activity === null || $phase === null || $date === null || $hours === null) {
            return null;
        }

        // same shape as an entry made in the form: the day, starting at 9:00, for the given hours
        $timezone = new \DateTimeZone($user->getTimezone());
        $begin = new \DateTime($date->format('Y-m-d') . ' 09:00:00', $timezone);
        $seconds = (int) round($hours * 3600);
        $end = (clone $begin)->modify('+' . $seconds . ' seconds');

        $timesheet = new Timesheet();
        $timesheet->setUser($user);
        $this->dispatcher->dispatch(new TimesheetMetaDefinitionEvent($timesheet));
        $timesheet->setBegin($begin);
        $timesheet->setEnd($end);
        $timesheet->setDuration($seconds);
        $timesheet->setProject($project);
        $timesheet->setActivity($activity);
        $description = trim((string) $row['Description']);
        $timesheet->setDescription($description !== '' ? $description : null);
        $timesheet->setBillableMode(Timesheet::BILLABLE_AUTOMATIC);

        $this->setMeta($timesheet, Phase::TIMESHEET_META_FIELD, (string) $phase->getName());
        $this->setMeta($timesheet, TimesheetStatusSubscriber::FIELD, $status);
        if ($task !== null) {
            $this->setMeta($timesheet, Task::TIMESHEET_META_FIELD, $task);
        }

        return $timesheet;
    }

    private function setMeta(Timesheet $timesheet, string $name, string $value): void
    {
        $meta = $timesheet->getMetaField($name);
        if ($meta === null) {
            $meta = new TimesheetMeta();
            $meta->setName($name);
            $timesheet->setMetaField($meta);
        }
        $meta->setValue($value);
    }

    /**
     * @param array<string> $errors
     * @param array<string, mixed> $cache
     */
    private function findProject(User $user, string $name, array &$errors, array &$cache): ?Project
    {
        if ($name === '') {
            $errors[] = 'Project is missing.';

            return null;
        }
        $key = 'projects-' . $user->getId();
        $cache[$key] ??= $this->tasks->getProjects($user);
        foreach ($cache[$key] as $project) {
            if ($this->same((string) $project->getName(), $name)) {
                return $project;
            }
        }
        $errors[] = 'Project "' . $name . '" does not exist or ' . $user->getDisplayName() . ' is not mapped to it.';

        return null;
    }

    /**
     * @return array<Phase> the phases offered for this project: its own, or the ones for all projects
     */
    private function phasesOf(Project $project): array
    {
        $repository = $this->entityManager->getRepository(Phase::class);
        $own = $repository->findBy(['project' => $project], ['position' => 'ASC', 'name' => 'ASC']);

        return $own !== [] ? $own : $repository->findBy(['project' => null], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * @param array<string> $errors
     */
    private function findPhase(Project $project, string $name, array &$errors): ?Phase
    {
        if ($name === '') {
            $errors[] = 'Phase is missing.';

            return null;
        }
        foreach ($this->phasesOf($project) as $phase) {
            if ($this->same((string) $phase->getName(), $name)) {
                return $phase;
            }
        }
        $errors[] = 'Phase "' . $name . '" is not a phase of the project "' . $project->getName() . '".';

        return null;
    }

    /**
     * @return array<Activity> the activities linked to the phase that can be used on the project
     */
    private function activitiesOf(Project $project, Phase $phase): array
    {
        $activities = [];
        foreach ($phase->getActivities() as $activity) {
            if ($activity->isVisible() && ($activity->getProject() === null || $activity->getProject()->getId() === $project->getId())) {
                $activities[] = $activity;
            }
        }
        usort($activities, static fn (Activity $a, Activity $b) => strcasecmp((string) $a->getName(), (string) $b->getName()));

        return $activities;
    }

    /**
     * @param array<string> $errors
     */
    private function findActivity(Project $project, Phase $phase, string $name, array &$errors): ?Activity
    {
        if ($name === '') {
            $errors[] = 'Activity is missing.';

            return null;
        }
        foreach ($this->activitiesOf($project, $phase) as $activity) {
            if ($this->same((string) $activity->getName(), $name)) {
                return $activity;
            }
        }
        $errors[] = 'Activity "' . $name . '" is not an activity of the phase "' . $phase->getName() . '".';

        return null;
    }

    /**
     * @param array<string> $errors
     * @return string|null what is stored on the entry: the name of a standard task, or the number of an assigned task
     */
    private function findTask(User $user, Project $project, Activity $activity, string $name, array &$errors): ?string
    {
        foreach ($this->entityManager->getRepository(ActivityTask::class)->findBy(['activity' => $activity]) as $standard) {
            if ($this->same((string) $standard->getName(), $name)) {
                return (string) $standard->getName();
            }
        }
        foreach ($this->tasks->getMyTasks($user, false) as $assigned) {
            $sameActivity = $assigned->getActivity() === null || $assigned->getActivity()->getId() === $activity->getId();
            if ($assigned->getProject()?->getId() === $project->getId() && $sameActivity && $this->same((string) $assigned->getTitle(), $name)) {
                return (string) $assigned->getId();
            }
        }
        $errors[] = 'Task "' . $name . '" is not a task of the activity "' . $activity->getName() . '".';

        return null;
    }

    /**
     * Every project > phase > activity > task the user can book on, for the "Allowed values" sheet.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function getCombinations(User $user): array
    {
        $projects = $this->tasks->getProjects($user);
        // the non-project work first: it is what everybody has
        usort($projects, static fn (Project $a, Project $b) => ($b->getName() === Phase::NON_PROJECT_NAME) <=> ($a->getName() === Phase::NON_PROJECT_NAME) ?: strcasecmp((string) $a->getName(), (string) $b->getName()));

        $taskRepository = $this->entityManager->getRepository(ActivityTask::class);
        $lines = [];
        foreach ($projects as $project) {
            foreach ($this->phasesOf($project) as $phase) {
                $activities = $this->activitiesOf($project, $phase);
                if ($activities === []) {
                    $lines[] = [(string) $project->getName(), (string) $phase->getName(), '', ''];
                    continue;
                }
                foreach ($activities as $activity) {
                    $tasks = $taskRepository->findBy(['activity' => $activity], ['position' => 'ASC', 'name' => 'ASC']);
                    if ($tasks === []) {
                        $lines[] = [(string) $project->getName(), (string) $phase->getName(), (string) $activity->getName(), ''];
                        continue;
                    }
                    foreach ($tasks as $task) {
                        $lines[] = [(string) $project->getName(), (string) $phase->getName(), (string) $activity->getName(), (string) $task->getName()];
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<int, array<string, mixed>>|null rows keyed by their line number in the file; null when the header is wrong
     */
    private function readRows(string $path): ?array
    {
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        $columns = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();
                    if ($columns === null) {
                        // where each column is, by its name in the first row
                        $columns = [];
                        foreach ($cells as $index => $title) {
                            foreach (self::HEADERS as $header) {
                                if (\is_string($title) && $this->same($title, $header)) {
                                    $columns[$header] = $index;
                                }
                            }
                        }
                        if (\count($columns) !== \count(self::HEADERS)) {
                            return null;
                        }
                        continue;
                    }
                    $values = [];
                    $empty = true;
                    foreach ($columns as $header => $index) {
                        $values[$header] = $cells[$index] ?? '';
                        if ($this->text($values[$header]) !== '') {
                            $empty = false;
                        }
                    }
                    if (!$empty) {
                        $rows[$line] = $values;
                    }
                }
                break; // only the first sheet holds entries
            }
        } finally {
            $reader->close();
        }

        return $columns === null ? null : $rows;
    }

    private function parseDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            // an Excel date kept as its day number
            if ($value > 20000 && $value < 80000) {
                return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days');
            }

            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        foreach (['!Y-m-d', '!d-m-Y', '!d/m/Y', '!d.m.Y', '!d-M-Y', '!d-M-y', '!Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $text);
            $problems = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    private function text(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
