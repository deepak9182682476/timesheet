<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\WorkModel\WorkModelService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the time entry form the items the person can pick (see partials/work-item-cascade.html.twig).
 */
final class WorkItemExtension extends AbstractExtension
{
    public function __construct(private readonly WorkModelService $models, private readonly Security $security)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('work_item_form_data', [$this, 'formData']),
            new TwigFunction('project_model_name', [$this, 'modelName']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formData(mixed $timesheet, bool $forOthers = false): ?array
    {
        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return null;
        }

        try {
            return $this->models->getFormData($user, $timesheet instanceof Timesheet ? $timesheet : new Timesheet(), $forOthers);
        } catch (\Throwable) {
            // never let the picker break the form: without the data the form shows its usual fields
            return null;
        }
    }

    public function modelName(Project $project): string
    {
        return $this->models->getModelName($project);
    }
}
