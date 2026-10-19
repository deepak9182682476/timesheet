<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\User;
use App\Timesheet\TimesheetReminderService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * timesheet_reminders(): the logged-in person's reminders for the last day to enter time
 * (the notification bell and the pop-up, see partials/timesheet-reminder.html.twig).
 */
final class TimesheetReminderExtension extends AbstractExtension
{
    /** @var array<string, mixed>|null worked out once per page */
    private ?array $reminders = null;

    public function __construct(
        private readonly TimesheetReminderService $reminderService,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('timesheet_reminders', [$this, 'reminders']),
        ];
    }

    /**
     * @return array{own: array<string, mixed>|null, team: array<string, mixed>|null, count: int}
     */
    public function reminders(): array
    {
        if ($this->reminders !== null) {
            return $this->reminders;
        }
        $own = null;
        $team = null;
        $user = $this->security->getUser();
        if ($user instanceof User) {
            try {
                $own = $this->reminderService->getOwnReminder($user);
                $team = $this->reminderService->getTeamReminder($user);
            } catch (\Throwable) {
                // a reminder must never break a page
            }
        }

        return $this->reminders = ['own' => $own, 'team' => $team, 'count' => ($own !== null ? 1 : 0) + ($team !== null ? \count($team['people']) : 0)];
    }
}
