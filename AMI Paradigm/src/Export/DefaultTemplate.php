<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Export;

use App\Entity\MetaTableTypeInterface;
use App\Entity\User;
use App\Event\ActivityMetaDisplayEvent;
use App\Event\CustomerMetaDisplayEvent;
use App\Event\MetaDisplayEventInterface;
use App\Event\ProjectMetaDisplayEvent;
use App\Event\TimesheetMetaDisplayEvent;
use App\Event\UserPreferenceDisplayEvent;
use App\Repository\Query\ActivityQuery;
use App\Repository\Query\CustomerQuery;
use App\Repository\Query\ProjectQuery;
use App\Repository\Query\TimesheetQuery;
use App\WorkModel\ExportContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class DefaultTemplate implements TemplateInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $id,
        private readonly ?string $locale = 'en',
        private readonly string $title = 'default',
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [];
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return MetaTableTypeInterface[]
     */
    private function findMetaColumns(MetaDisplayEventInterface $event): array
    {
        $this->eventDispatcher->dispatch($event);

        return $event->getFields();
    }

    /**
     * @return array<int, string>
     */
    public function getColumns(TimesheetQuery $query): array
    {
        // @deprecated from 2.36 - will be removed with 3.0
        $durationFormatter = 'duration';
        if (($user = $query->getCurrentUser()) instanceof User) {
            $durationFormatter = $user->isExportDecimal() ? 'duration_decimal' : 'duration';
        }

        // Only what people need to read a timesheet, in the order of the entry form:
        // date, who, project, the levels of the project's model, activity, task, hours, description.
        // The levels follow the kind of work that is exported (Epic, Feature, User Story for Agile, and so on).
        $layout = ExportContext::get();

        $columns = ['date'];
        if ($layout['showEmployee']) {
            $columns[] = 'user.alias';
        }
        if ($layout['showProject']) {
            $columns[] = 'project.name';
        }
        foreach (array_keys($layout['columns']) as $metaName) {
            $columns[] = 'timesheet.meta.' . $metaName;
        }
        $columns[] = 'activity.name';
        $columns[] = 'timesheet.meta.task';
        $columns[] = $durationFormatter;
        $columns[] = 'description';

        /*
         * The full list of columns the export had before, switched off: start and end time, prices, e-mail,
         * staff number, customer, numbers of customer and project, and every custom field of customers,
         * projects, activities and users. To bring one back, add its name to $columns above.
         *
         * 'begin', 'end', 'currency', 'rate', 'internal_rate', 'hourly_rate', 'fixed_rate', 'user.name', 'user.email',
         * 'user.account_number', 'customer.name', 'billable', 'tags', 'type', 'category', 'customer.number',
         * 'project.number', 'customer.vat_id', 'project.order_number'
         */

        return $columns;
    }
}
