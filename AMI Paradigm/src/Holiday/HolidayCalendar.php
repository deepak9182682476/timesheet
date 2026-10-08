<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Holiday;

/**
 * The AMIP holiday calendar for 2026 as data, typed in from the calendar picture
 * (amip-holiday-calendar-2026.png in this folder).
 *
 * Per office location each day is one of:
 *   H = holiday, O = optional holiday, W = working day
 *
 * Used to check that an "Optional holiday" is applied for on a date that really
 * is an optional holiday at the person's location.
 */
final class HolidayCalendar
{
    public const HOLIDAY = 'H';
    public const OPTIONAL = 'O';
    public const WORKING = 'W';

    /** Office locations, in the column order of the calendar */
    public const LOCATIONS = ['Vadodara', 'Bangalore/Mysore', 'Chennai', 'Hyderabad', 'Mumbai/Pune', 'Delhi/NCR'];

    /** The user preference holding a person's office (set by an administrator on the person's preferences) */
    public const USER_PREFERENCE = 'office_location';

    /**
     * The office a person belongs to, or null when none is set.
     */
    public static function getUserLocation(\App\Entity\User $user): ?string
    {
        $location = $user->getPreferenceValue(self::USER_PREFERENCE);

        return \is_string($location) && \in_array($location, self::LOCATIONS, true) ? $location : null;
    }

    /**
     * date (Y-m-d) => [name, one code per location in the order of LOCATIONS]
     */
    private const DAYS = [
        '2026-01-14' => ['Makar Sankranti', 'H', 'W', 'O', 'W', 'H', 'H'],
        '2026-01-15' => ['Makar Sankranti/Pongal', 'O', 'O', 'H', 'H', 'W', 'W'],
        '2026-01-26' => ['Republic Day', 'H', 'H', 'H', 'H', 'H', 'H'],
        '2026-03-03' => ['Holi', 'W', 'W', 'W', 'W', 'O', 'W'],
        '2026-03-04' => ['Holi', 'O', 'O', 'W', 'W', 'W', 'H'],
        '2026-03-19' => ['Ugadi/Gudi Padwa', 'O', 'H', 'O', 'H', 'H', 'O'],
        '2026-03-20' => ['Id-Ul-Fitr (Ramzan)', 'O', 'O', 'O', 'O', 'O', 'O'],
        '2026-04-03' => ['Good Friday', 'O', 'O', 'O', 'O', 'O', 'O'],
        '2026-04-14' => ['Meshadi (Tamil New Year\'s Day)/Ambedkar Jayanthi', 'W', 'W', 'O', 'W', 'W', 'W'],
        '2026-05-01' => ['May Day', 'H', 'H', 'H', 'H', 'H', 'H'],
        '2026-06-02' => ['Telangana Formation Day', 'W', 'W', 'W', 'H', 'W', 'W'],
        '2026-08-28' => ['Raksha Bandhan', 'O', 'O', 'W', 'O', 'O', 'O'],
        '2026-09-04' => ['Janmashtami', 'O', 'O', 'O', 'O', 'O', 'O'],
        '2026-09-14' => ['Ganesh Chaturthi/Vinayaka Chaturthi', 'H', 'H', 'H', 'H', 'H', 'H'],
        '2026-10-02' => ['Gandhi Jayanti', 'H', 'H', 'H', 'H', 'H', 'H'],
        '2026-10-19' => ['Ayudha Pooja', 'W', 'O', 'H', 'O', 'O', 'O'],
        '2026-10-20' => ['Dussehra/Vijayadasami', 'H', 'H', 'O', 'O', 'O', 'O'],
        '2026-11-09' => ['Diwali', 'H', 'H', 'H', 'O', 'H', 'H'],
        '2026-11-11' => ['Diwali (Bhai Dhuj)', 'O', 'O', 'O', 'O', 'O', 'O'],
        '2026-12-25' => ['Christmas Day', 'H', 'H', 'H', 'H', 'H', 'H'],
    ];

    /**
     * The optional holidays of one location.
     *
     * @return array<string, string> holiday name keyed by date (Y-m-d)
     */
    public function getOptionalHolidays(string $location): array
    {
        return $this->getDays($location, self::OPTIONAL);
    }

    /**
     * The fixed holidays of one location.
     *
     * @return array<string, string> holiday name keyed by date (Y-m-d)
     */
    public function getHolidays(string $location): array
    {
        return $this->getDays($location, self::HOLIDAY);
    }

    /**
     * Days that are a holiday at every office. Nobody works on these, whichever office they belong to,
     * so leave cannot be taken on them and they are never counted as leave days.
     *
     * @return array<string, string> holiday name keyed by date (Y-m-d)
     */
    public function getHolidaysEverywhere(): array
    {
        $days = [];
        foreach (self::DAYS as $date => $row) {
            if (\array_slice($row, 1) === array_fill(0, \count(self::LOCATIONS), self::HOLIDAY)) {
                $days[$date] = $row[0];
            }
        }

        return $days;
    }

    /**
     * Every festival day of the calendar, whatever it is at each location.
     *
     * @return array<string, string> festival name keyed by date (Y-m-d)
     */
    public function getFestivals(): array
    {
        $days = [];
        foreach (self::DAYS as $date => $row) {
            $days[$date] = $row[0];
        }

        return $days;
    }

    /**
     * The whole calendar: each festival day with what it is at every location.
     *
     * @return array<string, array{name: string, codes: array<string, string>}> keyed by date (Y-m-d)
     */
    public function getDaysByLocation(): array
    {
        $days = [];
        foreach (self::DAYS as $date => $row) {
            $codes = [];
            foreach (self::LOCATIONS as $column => $location) {
                $codes[$location] = $row[$column + 1];
            }
            $days[$date] = ['name' => $row[0], 'codes' => $codes];
        }

        return $days;
    }

    /**
     * Optional holidays of every location, for the leave form.
     *
     * @return array<string, array<string, string>> location => [date => name]
     */
    public function getOptionalHolidaysByLocation(): array
    {
        $all = [];
        foreach (self::LOCATIONS as $location) {
            $all[$location] = $this->getOptionalHolidays($location);
        }

        return $all;
    }

    /**
     * @return array<string, string>
     */
    private function getDays(string $location, string $code): array
    {
        $column = array_search($location, self::LOCATIONS, true);
        if ($column === false) {
            return [];
        }

        $days = [];
        foreach (self::DAYS as $date => $row) {
            if ($row[$column + 1] === $code) {
                $days[$date] = $row[0];
            }
        }

        return $days;
    }
}
