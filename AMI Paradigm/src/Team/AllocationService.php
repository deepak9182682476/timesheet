<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Team;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Allocations of people to projects (Team Allocation):
 * - every person on a project has one Allocation ID for it (ALC-0001, ALC-0002 ...), unique in the system
 * - every time they work on it is a period: Allocation No. 1, 2 ... with a from and to date
 * - a period lies within the project's start and end date, and periods of one allocation do not overlap
 */
final class AllocationService
{
    public const PREFIX = 'ALC-';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * The allocations of these people on these projects, with their periods.
     *
     * @param array<int> $projectIds
     * @param array<int> $userIds
     * @return array<int, array<int, array{id: int, code: string, periods: array<int, array{id: int, number: int, from: string, to: string}>}>> user ID => project ID => allocation
     */
    public function getAllocations(array $projectIds, array $userIds): array
    {
        $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if ($projectIds === [] || $userIds === []) {
            return [];
        }
        $rows = $this->db()->fetchAllAssociative(
            'SELECT a.id, a.code, a.project_id, a.user_id, p.id AS period_id, p.number, p.start_date, p.end_date
             FROM kimai2_project_allocations a
             LEFT JOIN kimai2_project_allocation_periods p ON p.allocation_id = a.id
             WHERE a.project_id IN (' . implode(',', $projectIds) . ') AND a.user_id IN (' . implode(',', $userIds) . ')
             ORDER BY p.number'
        );
        $result = [];
        foreach ($rows as $row) {
            $user = (int) $row['user_id'];
            $project = (int) $row['project_id'];
            $result[$user][$project] ??= ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'periods' => []];
            if ($row['period_id'] !== null) {
                $result[$user][$project]['periods'][] = [
                    'id' => (int) $row['period_id'],
                    'number' => (int) $row['number'],
                    'from' => (string) $row['start_date'],
                    'to' => (string) $row['end_date'],
                ];
            }
        }

        return $result;
    }

    /**
     * Time can only be logged on a project on the days the person is allocated to it: null when the day lies in one
     * of their periods, else why not.
     */
    public function getLoggingProblem(User $person, int $projectId, string $projectName, \DateTimeInterface $day): ?string
    {
        $date = $day->format('Y-m-d');
        $periods = $this->db()->fetchAllAssociative(
            'SELECT a.code, p.number, p.start_date, p.end_date FROM kimai2_project_allocations a
             JOIN kimai2_project_allocation_periods p ON p.allocation_id = a.id
             WHERE a.project_id = ? AND a.user_id = ? ORDER BY p.start_date',
            [$projectId, (int) $person->getId()]
        );
        foreach ($periods as $period) {
            if ($date >= (string) $period['start_date'] && $date <= (string) $period['end_date']) {
                return null;
            }
        }
        $pretty = static fn (string $value) => (new \DateTimeImmutable($value))->format('d M Y');
        $who = $person->getDisplayName();
        if ($periods === []) {
            return \sprintf('%s has no allocation on "%s" yet, so no time can be logged on it. A Project Lead or Project Manager adds the allocation dates on the "Team Allocation" page.', $who, $projectName);
        }
        $list = array_map(static fn (array $p) => $p['code'] . ' No. ' . $p['number'] . ' (' . $pretty((string) $p['start_date']) . ' to ' . $pretty((string) $p['end_date']) . ')', $periods);

        return \sprintf('%s is not allocated to "%s" on %s. Allocation: %s. A Project Lead or Project Manager can add a period on the "Team Allocation" page.', $who, $projectName, $day->format('d-M-y'), implode(', ', $list));
    }

    /**
     * What is wrong with a new period, or null.
     *
     * @param array{start: string|null, end: string|null} $projectDates
     */
    public function check(int $projectId, string $projectName, array $projectDates, int $userId, string $from, string $to): ?string
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return 'Enter the allocation From and To dates for ' . $projectName . '.';
        }
        if ($end < $start) {
            return 'The allocation To date is before the From date (' . $projectName . ').';
        }
        $pretty = static fn (string $date) => (new \DateTimeImmutable($date))->format('d M Y');
        if ($projectDates['start'] !== null && $from < $projectDates['start']) {
            return 'The allocation on ' . $projectName . ' cannot start before the project starts (' . $pretty($projectDates['start']) . ').';
        }
        if ($projectDates['end'] !== null && $to > $projectDates['end']) {
            return 'The allocation on ' . $projectName . ' cannot end after the project ends (' . $pretty($projectDates['end']) . ').';
        }
        $overlap = $this->db()->fetchAssociative(
            'SELECT a.code, p.number, p.start_date, p.end_date FROM kimai2_project_allocation_periods p
             JOIN kimai2_project_allocations a ON a.id = p.allocation_id
             WHERE a.project_id = ? AND a.user_id = ? AND p.start_date <= ? AND p.end_date >= ?',
            [$projectId, $userId, $to, $from]
        );
        if ($overlap !== false) {
            return 'These dates overlap ' . $overlap['code'] . ' Allocation No. ' . $overlap['number'] . ' on ' . $projectName
                . ' (' . $pretty((string) $overlap['start_date']) . ' to ' . $pretty((string) $overlap['end_date']) . ').';
        }

        return null;
    }

    /**
     * Adds a period (the next Allocation No.), creating the person's allocation on the project when it is the first.
     *
     * @return array{code: string, number: int}
     */
    public function addPeriod(int $projectId, int $userId, string $from, string $to, User $by): array
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $allocation = $db->fetchAssociative('SELECT id, code FROM kimai2_project_allocations WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
            if ($allocation === false) {
                // a placeholder code first: the real one comes from the ID, so it is unique
                $db->insert('kimai2_project_allocations', ['code' => 'NEW-' . bin2hex(random_bytes(6)), 'project_id' => $projectId, 'user_id' => $userId]);
                $id = (int) $db->lastInsertId();
                $code = self::PREFIX . str_pad((string) $id, 4, '0', \STR_PAD_LEFT);
                $db->update('kimai2_project_allocations', ['code' => $code], ['id' => $id]);
                $allocation = ['id' => $id, 'code' => $code];
            }
            $number = (int) $db->fetchOne('SELECT COALESCE(MAX(number), 0) FROM kimai2_project_allocation_periods WHERE allocation_id = ?', [$allocation['id']]) + 1;
            $db->insert('kimai2_project_allocation_periods', [
                'allocation_id' => $allocation['id'],
                'number' => $number,
                'start_date' => $from,
                'end_date' => $to,
                'created_by_id' => $by->getId(),
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }

        return ['code' => (string) $allocation['code'], 'number' => $number];
    }

    /**
     * Takes a period out (its number is not given to another period).
     *
     * @return array{code: string, number: int, user: int}|null
     */
    public function removePeriod(int $periodId, array $projectIds): ?array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT p.id, p.number, a.code, a.user_id, a.project_id FROM kimai2_project_allocation_periods p JOIN kimai2_project_allocations a ON a.id = p.allocation_id WHERE p.id = ?',
            [$periodId]
        );
        if ($row === false || !\in_array((int) $row['project_id'], array_map('intval', $projectIds), true)) {
            return null;
        }
        $this->db()->delete('kimai2_project_allocation_periods', ['id' => $periodId]);

        return ['code' => (string) $row['code'], 'number' => (int) $row['number'], 'user' => (int) $row['user_id']];
    }
}
