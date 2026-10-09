<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\WorkModel;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\WorkItem;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Project mapping from an Excel file: the whole breakdown of a project and who each item is assigned to.
 *
 * The file has one column per level of the project's model (Epic, Feature, User Story, Activity, Task
 * for an Agile project) and a last column "Assigned to". It is a plain table: one row per item, with the
 * names of everything above it written out in the cells before it.
 * - what a row names is created when it does not exist yet; what exists already is found by its name,
 *   so a file can be uploaded again without doubling anything
 * - "Assigned to" holds the usernames (or e-mails, or names) of the people for the last item of the row,
 *   separated by commas. Empty leaves the people as they are, "-" takes everybody off.
 * - the people of a row are also added to everything above its last item (the Epic, Feature and so on of the row)
 * - a row can stop early (only the Epic, say) to assign something higher up
 * - a cell left empty before the first filled one is read as "the same as in the row above"
 * The current mapping can be downloaded in the same format, changed in Excel and uploaded again.
 * A file made for another model does not have the right columns and is refused as a whole.
 */
final class WorkItemImportService
{
    public const ASSIGNED = 'Assigned to';
    public const CLEAR = '-';
    public const MAX_ROWS = 20000;

    /** @var array<int, string> the column names found in the first row of the uploaded file */
    private array $header = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkModelService $models
    ) {
    }

    /**
     * @return array<int, string> the column names of the file for this project
     */
    public function getHeaders(Project $project): array
    {
        // no "Assigned to" column any more: everything of a project is for all the people in its teams (Team Mapping)
        // return array_merge($this->models->getLevels($project), [self::ASSIGNED]);
        return $this->models->getLevels($project);
    }

    /**
     * Writes the Excel file and returns its path: the empty template, or (with $withData) the current mapping.
     */
    public function createFile(Project $project, User $user, bool $withData): string
    {
        $file = tempnam(sys_get_temp_dir(), 'project-mapping-') . '.xlsx';
        $bold = (new Style())->setFontBold();
        $levels = $this->models->getLevels($project);
        $headers = $this->getHeaders($project);

        $writer = new Writer();
        $writer->openToFile($file);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Mapping');
        foreach ($headers as $index => $header) {
            $sheet->setColumnWidth($header === self::ASSIGNED ? 40 : 32, $index + 1);
        }
        $writer->addRow(Row::fromValues($headers, $bold));

        if ($withData) {
            foreach ($this->models->getTree($project) as $item) {
                $cells = array_fill(0, \count($levels), '');
                foreach ($item->getPath() as $level => $step) {
                    if (isset($cells[$level])) {
                        $cells[$level] = (string) $step->getName();
                    }
                }
                // $people = [];
                // foreach ($item->getUsers() as $person) {
                //     $people[] = $person->getUserIdentifier();
                // }
                // sort($people);
                // $cells[] = implode(', ', $people);
                $writer->addRow(Row::fromValues($cells));
            }
        }

        $help = $writer->addNewSheetAndMakeItCurrent();
        $help->setName('How to fill');
        $help->setColumnWidth(110, 1);
        foreach ([
            'One row is one ' . mb_strtolower((string) end($levels)) . '. Fill in every cell of the row: ' . implode(', ', $levels) . '.',
            'Write the names again on every row. The ' . $levels[0] . ' is created the first time it appears and used again on the rows after it, so nothing is doubled.',
            // '"' . self::ASSIGNED . '" is for the last item of the row: usernames (or e-mails, or names) separated by commas. See the sheet "People".',
            // 'The same people are also put on everything before it in the row (the ' . implode(', ', \array_slice($levels, 0, -1)) . '), so they can book on those as well.',
            // 'A row can stop early: a row with only the ' . $levels[0] . ' and "' . self::ASSIGNED . '" assigns the whole ' . $levels[0] . '. Items without people of their own are for the same people as the item above them.',
            // '"' . self::ASSIGNED . '" left empty changes nothing. A single "' . self::CLEAR . '" takes everybody off the item.',
            'Everything in the file is for all the people in the project\'s teams (Team Allocation). Nothing is assigned to single people.',
            'A row can stop early, for example a row with only the ' . $levels[0] . '.',
            'Items that exist already are found by their name, so the file can be uploaded again after a correction.',
            // 'To change who things are assigned to later: download the current mapping, change "' . self::ASSIGNED . '" in Excel and upload the file again.',
            'Nothing is ever deleted by an upload. Items are deleted on the "Task Creation" page.',
            'Keep the first row (the column names) as it is. Up to ' . self::MAX_ROWS . ' rows per file.',
            '',
            'Example:',
        ] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues($headers, $bold));
        foreach ($this->exampleRows($levels, $user) as $example) {
            $writer->addRow(Row::fromValues($example));
        }

        // no "People" sheet any more: nothing is assigned to single people
        // $people = $writer->addNewSheetAndMakeItCurrent();
        // $people->setName('People');
        // $people->setColumnWidth(30, 1);
        // $people->setColumnWidth(36, 2);
        // $writer->addRow(Row::fromValues(['Username', 'Name'], $bold));
        // foreach ($this->models->getAssignableUsers($user) as $person) {
        //     $writer->addRow(Row::fromValues([$person->getUserIdentifier(), $person->getDisplayName()]));
        // }

        $writer->setCurrentSheet($sheet);
        $writer->close();

        return $file;
    }

    /**
     * A few rows showing how the file is filled, in the words of the project's model: plain rows,
     * every cell filled in, one row per task.
     *
     * @param array<int, string> $levels
     * @return array<int, array<int, string>>
     */
    public function exampleRows(array $levels, User $user): array
    {
        $me = $user->getUserIdentifier();
        $upper = \count($levels) - 2;

        $path = static function (string $which) use ($levels, $upper): array {
            $cells = [];
            for ($level = 0; $level < $upper; $level++) {
                // the second example branches off below the top level
                $cells[] = ($level === 0 ? 'First' : $which) . ' ' . $levels[$level];
            }

            return $cells;
        };

        // return [
        //     array_merge($path('First'), ['Development', 'Build the screen', $me . ', second.username']),
        //     array_merge($path('First'), ['Development', 'Write the tests', $me]),
        //     array_merge($path('First'), ['Testing', 'Run the tests', 'second.username']),
        //     array_merge($path('Second'), ['Development', 'Build the API', $me . ', second.username']),
        // ];
        return [
            array_merge($path('First'), ['Development', 'Build the screen']),
            array_merge($path('First'), ['Development', 'Write the tests']),
            array_merge($path('First'), ['Testing', 'Run the tests']),
            array_merge($path('Second'), ['Development', 'Build the API']),
        ];
    }

    /**
     * Reads the file and checks every row. Correct rows are stored, rows with a problem are rejected and reported.
     *
     * @return array{created: int, assigned: int, rows: int, errors: array<int, array<string>>}
     */
    public function import(Project $project, User $uploader, string $path): array
    {
        @set_time_limit(300);

        // existing: rows whose items were all there already (nothing is added twice)
        $result = ['created' => 0, 'assigned' => 0, 'rows' => 0, 'existing' => 0, 'errors' => []];
        $levels = $this->models->getLevels($project);
        $depth = \count($levels);

        $rows = $this->readRows($path, $levels);
        if ($rows === null) {
            $wanted = 'This project is ' . $this->models->getModelName($project) . ', so the first row has to hold the column names: ' . implode(', ', $this->getHeaders($project)) . '. Please use the template of this project.';
            // a file made for another model is the usual reason: say so
            $other = $this->guessModel($project);
            $result['errors'][1] = [$other !== null ? 'This is a file for ' . $other . ' projects. ' . $wanted : $wanted];

            return $result;
        }
        if (\count($rows) > self::MAX_ROWS) {
            $result['errors'][1] = ['The file has more than ' . self::MAX_ROWS . ' rows. Please split it into smaller files.'];

            return $result;
        }
        if ($rows === []) {
            $result['errors'][2] = ['The file has no rows to upload.'];

            return $result;
        }

        // what the project has already, by the item above and the name (compared without case and extra spaces)
        $index = [];
        foreach ($this->entityManager->getRepository(WorkItem::class)->findBy(['project' => $project]) as $item) {
            $index[$item->getParent() !== null ? spl_object_id($item->getParent()) : 0][WorkModelService::nameKey($item->getName())] = $item;
        }

        // the people the uploader may assign, by username, e-mail and name
        $people = [];
        foreach ($this->models->getAssignableUsers($uploader) as $person) {
            foreach ([$person->getUserIdentifier(), (string) $person->getEmail(), $person->getDisplayName()] as $key) {
                if ($key !== '') {
                    $people[mb_strtolower($key)] ??= $person;
                }
            }
        }

        $previous = [];
        $changed = [];

        foreach ($rows as $line => $row) {
            $errors = [];
            $names = $row['names'];

            $first = null;
            $last = null;
            foreach ($names as $level => $name) {
                if ($name !== '') {
                    $first ??= $level;
                    $last = $level;
                }
            }
            if ($first === null || $last === null) {
                $result['errors'][$line] = ['The row names no item: "' . self::ASSIGNED . '" is filled in, but every other cell is empty.'];
                continue;
            }

            for ($level = 0; $level < $first; $level++) {
                $names[$level] = $previous[$level] ?? '';
                if ($names[$level] === '') {
                    $errors[] = $levels[$level] . ' is empty and there is no row above to take it from.';
                }
            }
            for ($level = $first; $level < $last; $level++) {
                if ($names[$level] === '') {
                    $errors[] = $levels[$level] . ' is empty, but ' . $levels[$last] . ' is filled in.';
                }
            }
            for ($level = $first; $level <= $last; $level++) {
                if (mb_strlen($names[$level]) > 150) {
                    $errors[] = $levels[$level] . ' is longer than 150 characters.';
                } elseif ($names[$level] !== '' && ctype_digit($names[$level])) {
                    $errors[] = $levels[$level] . ' "' . $names[$level] . '" is just a number. Please use a name.';
                }
            }

            // who the last item of the row is for
            $assign = null;
            $text = $row['assigned'];
            if ($text === self::CLEAR) {
                $assign = [];
            } elseif ($text !== '') {
                $assign = [];
                foreach (preg_split('/[,;\n]+/', $text) ?: [] as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $person = $people[mb_strtolower($part)] ?? null;
                    if ($person === null) {
                        $errors[] = '"' . $part . '" is not somebody you can assign. Use the usernames of the sheet "People".';
                        continue;
                    }
                    $assign[(int) $person->getId()] = $person;
                }
            }

            if ($errors !== []) {
                $result['errors'][$line] = $errors;
                // the rows below can still refer to what this row named
                if (!\in_array('', \array_slice($names, 0, $last + 1), true)) {
                    $previous = \array_slice($names, 0, $last + 1);
                }
                continue;
            }

            $previous = \array_slice($names, 0, $last + 1);
            $result['rows']++;

            $createdBefore = $result['created'];
            $parent = null;
            $above = [];
            for ($level = 0; $level <= $last && $level < $depth; $level++) {
                if ($parent !== null) {
                    $above[] = $parent;
                }
                $key = WorkModelService::nameKey($names[$level]);
                $slot = $parent !== null ? spl_object_id($parent) : 0;
                $item = $index[$slot][$key] ?? null;
                if ($item === null) {
                    $item = new WorkItem();
                    $item->setProject($project);
                    $item->setParent($parent);
                    $item->setName($names[$level]);
                    $item->setCreatedBy($uploader);
                    $this->models->prepare($item);
                    $index[$slot][$key] = $item;
                    $result['created']++;
                }
                $parent = $item;
            }

            if ($result['created'] === $createdBefore) {
                $result['existing']++;
            }

            if ($assign !== null && $parent !== null) {
                $before = [];
                foreach ($parent->getUsers() as $person) {
                    $before[(int) $person->getId()] = $person;
                }
                foreach ($before as $id => $person) {
                    if (!isset($assign[$id])) {
                        $parent->removeUser($person);
                        $changed[spl_object_id($parent)] = true;
                    }
                }
                foreach ($assign as $id => $person) {
                    if (!isset($before[$id])) {
                        $parent->addUser($person);
                        $changed[spl_object_id($parent)] = true;
                    }
                }

                // The people of the row are put on everything above the item as well (its Epic, Feature and so on),
                // so those show who works below them and can be booked on. People are only added there, never
                // taken off: somebody else's row may have put them on.
                foreach ($above as $ancestor) {
                    $has = [];
                    foreach ($ancestor->getUsers() as $person) {
                        $has[(int) $person->getId()] = true;
                    }
                    foreach ($assign as $id => $person) {
                        if (!isset($has[$id])) {
                            $ancestor->addUser($person);
                            $changed[spl_object_id($ancestor)] = true;
                        }
                    }
                }
            }
        }

        $result['assigned'] = \count($changed);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $ex) {
            $connection->rollBack();
            throw $ex;
        }

        return $result;
    }

    /**
     * The rows of the first sheet, by Excel row number. Null when the first row does not hold the column names.
     *
     * @param array<int, string> $levels
     * @return array<int, array{names: array<int, string>, assigned: string}>|null
     */
    private function readRows(string $path, array $levels): ?array
    {
        $reader = new Reader();
        $reader->open($path);
        $this->header = [];
        $rows = [];
        $columns = null;
        $assigned = null;
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
                            if (!\is_string($title)) {
                                continue;
                            }
                            $this->header[] = $title;
                            foreach ($levels as $level => $name) {
                                // "Category" was called "Phase" before: older files still work
                                if ($this->same($title, $name) || ($name === 'Category' && $this->same($title, 'Phase'))) {
                                    $columns[$level] = $index;
                                }
                            }
                            if ($this->same($title, self::ASSIGNED)) {
                                $assigned = $index;
                            }
                        }
                        if (\count($columns) !== \count($levels)) {
                            return null;
                        }
                        continue;
                    }

                    $names = [];
                    $empty = true;
                    foreach ($levels as $level => $name) {
                        $names[$level] = $this->text($cells[$columns[$level]] ?? '');
                        if ($names[$level] !== '') {
                            $empty = false;
                        }
                    }
                    // an "Assigned to" column of an older file is not used any more
                    // $people = $assigned !== null ? $this->text($cells[$assigned] ?? '') : '';
                    $people = '';
                    if (!$empty || $people !== '') {
                        $rows[$line] = ['names' => $names, 'assigned' => $people];
                    }
                }
                break; // only the first sheet holds the mapping
            }
        } finally {
            $reader->close();
        }

        return $columns === null ? null : $rows;
    }

    /**
     * Which other model the uploaded file was made for, going by its column names. Null when it cannot be told.
     */
    private function guessModel(Project $project): ?string
    {
        $names = [WorkModelService::AGILE => 'Agile', WorkModelService::WATERFALL => 'Waterfall', WorkModelService::PRESALES => 'Pre-Sales'];
        foreach (WorkModelService::LEVELS as $model => $levels) {
            if ($model === $this->models->getModel($project)) {
                continue;
            }
            // the first level is what tells the models apart: Epic, Module or Lead
            foreach ($this->header as $title) {
                if ($this->same($title, $levels[0])) {
                    return $names[$model];
                }
            }
        }

        return null;
    }

    private function text(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (\is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) (\is_scalar($value) ? $value : '')) ?? '');
    }

    private function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
