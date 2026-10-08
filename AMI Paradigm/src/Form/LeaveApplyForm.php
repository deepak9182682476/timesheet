<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\TeamEvent;
use App\Form\Type\DatePickerType;
use App\Holiday\HolidayCalendar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A person applies for their own leave.
 */
final class LeaveApplyForm extends AbstractType
{
    /** Kinds of leave offered in the form; the chosen one becomes the name shown on the dashboard */
    public const KINDS = ['Casual leave', 'Sick leave', 'Earned leave', 'Comp off', 'Optional holiday', 'Other leave'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', ChoiceType::class, [
                'label' => 'Leave type',
                'choices' => array_combine(self::KINDS, self::KINDS),
                'translation_domain' => false,
            ])
            ->add('startDate', DatePickerType::class, [
                'label' => 'From',
            ])
            ->add('endDate', DatePickerType::class, [
                'label' => 'To (last day of leave)',
                'required' => false,
                'help' => 'The last day you are away, not the day you return. Leave empty for a single day.',
            ])
            // only used for "Optional holiday": which office's optional holidays apply
            // filled in (and fixed) when an administrator set the person's office on their preferences
            ->add('location', ChoiceType::class, [
                'label' => 'Office location',
                'mapped' => false,
                'required' => false,
                'choices' => array_combine(HolidayCalendar::LOCATIONS, HolidayCalendar::LOCATIONS),
                'placeholder' => '',
                'translation_domain' => false,
                'data' => $options['office'],
                'disabled' => $options['office'] !== null,
                'help' => $options['office'] !== null ? 'Your office, set by the administrator.' : null,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Reason',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeamEvent::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'leave_apply',
            'office' => null,
        ]);
        $resolver->setAllowedTypes('office', ['null', 'string']);
    }
}
