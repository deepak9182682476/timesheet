<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Attendance;

use App\Entity\User;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel file for entering attendance in bulk: one row per person and day, "Present" Yes or No.
 */
final class AttendanceUpload
{
    public const HEADERS = ['Date', 'Employee ID', 'Name', 'Present (Yes/No)'];
    /** the longest period of one template */
    public const MAX_DAYS = 31;

    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    /**
     * A file with every person for every day from $from to $to; what is entered already is filled in.
     */
    public function createTemplate(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $file = tempnam(sys_get_temp_dir(), 'attendance-') . '.xlsx';
        $bold = (new Style())->setFontBold();
        $people = $this->attendance->getPeople();

        $writer = new Writer();
        $writer->openToFile($file);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Attendance');
        foreach ([14, 16, 30, 18] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues(self::HEADERS, $bold));
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $entered = $this->attendance->getDay($day);
            foreach ($people as $person) {
                $value = $entered[(int) $person->getId()] ?? null;
                $writer->addRow(Row::fromValues([
                    $day->format('Y-m-d'),
                    // the Employee ID, or the username for somebody without one
                    // (string) ($person->getAccountNumber() ?: $person->getUserIdentifier()),
                    // the Employee ID only (empty for somebody without one: the name is used for them)
                    (string) $person->getAccountNumber(),
                    $person->getDisplayName(),
                    $value === null ? '' : ($value ? 'Yes' : 'No'),
                ]));
            }
        }

        $help = $writer->addNewSheetAndMakeItCurrent();
        $help->setName('How to fill');
        $help->setColumnWidth(20, 1);
        $help->setColumnWidth(100, 2);
        $writer->addRow(Row::fromValues(['Column', 'What to enter'], $bold));
        foreach ([
            ['Date', 'The day, as YYYY-MM-DD (for example ' . $from->format('Y-m-d') . ') or as a normal Excel date.'],
            ['Employee ID', 'The person\'s Employee ID (a number). It is empty for somebody who has none yet: the name is used for them.'],
            ['Name', 'The person\'s name, as on the template. Only read when the Employee ID is empty.'],
            ['Present (Yes/No)', 'Yes: physically present in the office. No: not present. Leave empty to keep what is entered already.'],
            ['', ''],
            ['Good to know', 'Keep the first row (the column names) as it is. Uploading the same day again overwrites what was entered for it.'],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }

        $writer->setCurrentSheet($sheet);
        $writer->close();

        return $file;
    }

    /** the longest period of one export */
    public const MAX_EXPORT_DAYS = 366;

    /**
     * The attendance from $from to $to: one row per person and day (Yes / No / Not entered), and a summary per person.
     */
    public function createExport(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $file = tempnam(sys_get_temp_dir(), 'attendance-export-') . '.xlsx';
        $bold = (new Style())->setFontBold();
        $people = $this->attendance->getPeople();
        $entered = $this->attendance->getRange($from, $to);

        $writer = new Writer();
        $writer->openToFile($file);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Attendance');
        foreach ([14, 12, 16, 30, 16] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues(['Date', 'Day', 'Employee ID', 'Name', 'Present'], $bold));

        $totals = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            foreach ($people as $person) {
                $id = (int) $person->getId();
                $value = $entered[$date][$id] ?? null;
                $label = $value === null ? 'Not entered' : ($value ? 'Yes' : 'No');
                $totals[$id][$label] = ($totals[$id][$label] ?? 0) + 1;
                $writer->addRow(Row::fromValues([$date, $day->format('D'), (string) $person->getAccountNumber(), $person->getDisplayName(), $label]));
            }
        }

        $summary = $writer->addNewSheetAndMakeItCurrent();
        $summary->setName('Summary');
        foreach ([16, 30, 12, 14, 14] as $index => $width) {
            $summary->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues(['Attendance from ' . $from->format('d-M-y') . ' to ' . $to->format('d-M-y')], $bold));
        $writer->addRow(Row::fromValues(['Employee ID', 'Name', 'Present', 'Not present', 'Not entered'], $bold));
        foreach ($people as $person) {
            $id = (int) $person->getId();
            $writer->addRow(Row::fromValues([
                (string) $person->getAccountNumber(),
                $person->getDisplayName(),
                $totals[$id]['Yes'] ?? 0,
                $totals[$id]['No'] ?? 0,
                $totals[$id]['Not entered'] ?? 0,
            ]));
        }

        $writer->setCurrentSheet($sheet);
        $writer->close();

        return $file;
    }

    /**
     * Reads the file and saves the attendance it holds.
     *
     * @return array{saved: int, rows: int, errors: array<int, string>}
     */
    public function import(string $path, User $by): array
    {
        $people = [];
        $names = [];
        foreach ($this->attendance->getPeople() as $person) {
            if ((string) $person->getAccountNumber() !== '') {
                $people[mb_strtolower(trim((string) $person->getAccountNumber()))] = (int) $person->getId();
            }
            $people[mb_strtolower($person->getUserIdentifier())] ??= (int) $person->getId();
            $names[mb_strtolower(trim($person->getDisplayName()))] ??= (int) $person->getId();
        }

        $errors = [];
        $byDay = [];
        $rows = 0;
        $reader = new Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();
                    if ($columns === null) {
                        $columns = [];
                        foreach ($cells as $index => $title) {
                            foreach (self::HEADERS as $header) {
                                if (\is_string($title) && $this->same($title, $header)) {
                                    $columns[$header] = $index;
                                }
                            }
                            // "Present" alone is fine as well
                            if (\is_string($title) && $this->same($title, 'Present')) {
                                $columns[self::HEADERS[3]] = $index;
                            }
                        }
                        if (!isset($columns['Date'], $columns['Employee ID'], $columns[self::HEADERS[3]])) {
                            return ['saved' => 0, 'rows' => 0, 'errors' => [1 => 'The first row must hold the column names Date, Employee ID and Present (Yes/No). Please start from the template.']];
                        }
                        continue;
                    }
                    $dateValue = $cells[$columns['Date']] ?? '';
                    $idValue = $this->text($cells[$columns['Employee ID']] ?? '');
                    $presentValue = mb_strtolower($this->text($cells[$columns[self::HEADERS[3]]] ?? ''));
                    if ($this->text($dateValue) === '' && $idValue === '' && $presentValue === '') {
                        continue;
                    }
                    $rows++;
                    $date = $this->parseDate($dateValue);
                    if ($date === null) {
                        $errors[$line] = 'Date "' . $this->text($dateValue) . '" is not a date (use YYYY-MM-DD).';
                        continue;
                    }
                    // $userId = $people[mb_strtolower($idValue)] ?? null;
                    // without an Employee ID the person is found by their name
                    $nameValue = isset($columns['Name']) ? mb_strtolower($this->text($cells[$columns['Name']] ?? '')) : '';
                    $userId = $idValue !== '' ? ($people[mb_strtolower($idValue)] ?? null) : ($names[$nameValue] ?? null);
                    if ($userId === null) {
                        $errors[$line] = $idValue !== '' ? 'Employee ID "' . $idValue . '" was not found.' : 'Nobody is called "' . $nameValue . '" (and there is no Employee ID).';
                        continue;
                    }
                    if ($presentValue === '') {
                        continue;
                    }
                    if (\in_array($presentValue, ['yes', 'y', '1', 'present', 'true'], true)) {
                        $byDay[$date->format('Y-m-d')][$userId] = true;
                    } elseif (\in_array($presentValue, ['no', 'n', '0', 'absent', 'false'], true)) {
                        $byDay[$date->format('Y-m-d')][$userId] = false;
                    } else {
                        $errors[$line] = 'Present must be Yes or No, not "' . $presentValue . '".';
                    }
                }
                break; // only the first sheet holds attendance
            }
        } finally {
            $reader->close();
        }

        $saved = 0;
        foreach ($byDay as $day => $values) {
            $saved += $this->attendance->saveDay(new \DateTimeImmutable($day), $values, $by);
        }

        return ['saved' => $saved, 'rows' => $rows, 'errors' => $errors];
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
        if (\is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
