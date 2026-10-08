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
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Two more fields on every project:
 * - "Execution center": free text, where the project is carried out
 * - "Status": In progress or Completed (a new project starts as In progress)
 * The built-in "Non-Project Activities" and "Pre-Sales" do not get them.
 */
final class ProjectDetailsSubscriber implements EventSubscriberInterface
{
    public const EXECUTION_CENTER = 'execution_center';
    public const STATUS = 'project_status';
    public const STATUSES = [
        'in_progress' => 'In progress',
        'completed' => 'Completed',
    ];

    public function __construct(private readonly WorkModelService $models)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // after the "Model" field (ProjectModelSubscriber, priority 200)
            ProjectMetaDefinitionEvent::class => ['addToForm', 190],
            ProjectMetaDisplayEvent::class => ['addToLists', 190],
        ];
    }

    public function addToForm(ProjectMetaDefinitionEvent $event): void
    {
        $project = $event->getEntity();
        if (\in_array($this->models->getModel($project), [WorkModelService::NON_PROJECT, WorkModelService::PRESALES], true)) {
            return;
        }

        $project->setMetaField($this->executionCenter());

        $status = $this->status();
        // a project nobody set a status for is in progress
        $status->setValue('in_progress');
        $project->setMetaField($status);
    }

    public function addToLists(ProjectMetaDisplayEvent $event): void
    {
        $event->addField($this->executionCenter());
        $event->addField($this->status());
    }

    private function executionCenter(): ProjectMeta
    {
        $definition = new ProjectMeta();
        $definition->setName(self::EXECUTION_CENTER);
        $definition->setLabel('Execution center');
        $definition->setType(TextType::class);
        $definition->setOptions([
            'translation_domain' => false,
            'attr' => ['maxlength' => 100],
        ]);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);

        return $definition;
    }

    private function status(): ProjectMeta
    {
        $definition = new ProjectMeta();
        $definition->setName(self::STATUS);
        $definition->setLabel('Status');
        $definition->setType(ChoiceType::class);
        $definition->setOptions([
            'choices' => array_flip(self::STATUSES),
            'translation_domain' => false,
        ]);
        $definition->setIsRequired(true);
        $definition->setIsVisible(true);

        return $definition;
    }
}
