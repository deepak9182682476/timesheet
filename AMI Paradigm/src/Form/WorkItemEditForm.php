<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\User;
use App\Entity\WorkItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Create or change one item of a project's breakdown (an Epic, a Feature, a Lead ...) and say who it is for.
 */
final class WorkItemEditForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['several']) {
            // a new item: several can be added in one go, one name per line
            $builder->add('names', TextareaType::class, [
                'label' => $options['level_name'],
                'translation_domain' => false,
                'mapped' => false,
                'constraints' => [new NotBlank()],
                'attr' => ['autofocus' => 'autofocus', 'rows' => 4],
                'help' => 'To add several at once, write one name per line.',
            ]);
        } else {
            $builder->add('name', TextType::class, [
                'label' => $options['level_name'],
                'translation_domain' => false,
                'attr' => ['autofocus' => 'autofocus'],
            ]);
        }

        // Nothing is assigned to single people any more: everything of a project is for all the people in its teams (Team Mapping).
        /* $builder
            ->add('users', EntityType::class, [
                'label' => 'Assigned to',
                'translation_domain' => false,
                'class' => User::class,
                'choices' => $options['assignees'],
                'choice_label' => static fn (User $user) => $user->getDisplayName(),
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'help' => $options['parent_name'] !== null
                    ? 'Only these people can book time on it. Leave empty to use the same people as the ' . $options['parent_name'] . ' above.'
                    : 'Only these people can book time on it and on everything below it. Nobody can pick it while this is empty.',
            ])
        ; */
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkItem::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'work_item_edit',
            'assignees' => [],
            'level_name' => 'Name',
            'parent_name' => null,
            'several' => false,
        ]);
        $resolver->setAllowedTypes('several', 'bool');
        $resolver->setAllowedTypes('assignees', 'array');
        $resolver->setAllowedTypes('level_name', 'string');
        $resolver->setAllowedTypes('parent_name', ['null', 'string']);
    }
}
