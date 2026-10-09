<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\ProjectMeta;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\ProjectMetaDisplayEvent;
use App\WorkModel\WorkModelService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Adds the "Model" field (Agile / Waterfall) to every project. The administrator sets it when creating
 * the project; it decides what people pick on a time entry for that project (see WorkModelService).
 * "Non-Project Activities" and "Pre-Sales" are built-in and have no model to choose.
 */
final class ProjectModelSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly WorkModelService $models)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProjectMetaDefinitionEvent::class => ['addToForm', 200],
            ProjectMetaDisplayEvent::class => ['addToLists', 200],
        ];
    }

    public function addToForm(ProjectMetaDefinitionEvent $event): void
    {
        $project = $event->getEntity();
        if (\in_array($this->models->getModel($project), [WorkModelService::NON_PROJECT, WorkModelService::PRESALES], true)) {
            return;
        }

        $definition = $this->definition();
        // a project nobody picked a model for is Agile
        $definition->setValue(WorkModelService::AGILE);
        $project->setMetaField($definition);
    }

    public function addToLists(ProjectMetaDisplayEvent $event): void
    {
        $event->addField($this->definition());
    }

    private function definition(): ProjectMeta
    {
        $definition = new ProjectMeta();
        $definition->setName(WorkModelService::PROJECT_META);
        $definition->setLabel('Model');
        $definition->setType(ChoiceType::class);
        $definition->setOptions([
            'choices' => array_flip(WorkModelService::PROJECT_MODELS),
            'translation_domain' => false,
            'help' => 'Agile: Epic > Feature > User Story > Activity > Task. Waterfall: Module > Sub Module > Business Req > Activity > Task. Managers and leads create these on the "Task Creation" page.',
        ]);
        $definition->setIsRequired(true);
        $definition->setIsVisible(true);

        return $definition;
    }
}
