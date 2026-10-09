<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\Activity;
use App\Entity\Phase;
use App\Entity\Project;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create or change a phase (administrators only).
 */
final class PhaseEditForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Category',
                'attr' => ['autofocus' => 'autofocus'],
            ])
            ->add('project', EntityType::class, [
                'label' => 'Used for',
                'class' => Project::class,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
                'choice_label' => static fn (Project $project) => 'Only: ' . $project->getName(),
                'required' => false,
                'placeholder' => 'All projects (that have no categories of their own)',
                'help' => 'Pick "Only: ' . Phase::NON_PROJECT_NAME . '" for a non-project category. Leave it on "All projects" for a category of normal project work.',
            ])
            ->add('activities', EntityType::class, [
                'label' => 'Activities',
                'class' => Activity::class,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('a')->orderBy('a.name', 'ASC'),
                'choice_label' => static fn (Activity $activity) => $activity->getName() . ($activity->getProject() !== null ? ' (' . $activity->getProject()->getName() . ')' : ''),
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'help' => 'The activities offered on a time entry once this category is picked. Only these are offered.',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Order',
                'required' => false,
                'help' => 'Categories are listed from the lowest number to the highest.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Phase::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'phase_edit',
        ]);
    }
}
