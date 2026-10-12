<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\AdditionalHours;
use App\Form\Type\DatePickerType;
use App\Form\Type\TimePickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Additional hours worked on a weekend, a festival or holiday, or a night shift. The rules are checked in
 * AdditionalHoursController.
 */
final class AdditionalHoursForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workDate', DatePickerType::class, [
                'label' => 'Date',
                'translation_domain' => false,
                'constraints' => [new NotNull(message: 'Pick the day you worked.')],
                // 'help' => 'The weekend day, festival or holiday, or the day of the night shift. Up to ' . AdditionalHours::CREDIT_VALID_DAYS . ' days back.',
                'help' => 'Saturday or Sunday counts as weekend, a festival day of the holiday calendar as festival / holiday, any other day as night shift. Up to ' . AdditionalHours::CREDIT_VALID_DAYS . ' days back.',
            ])
            // "Worked on" is no longer asked: it follows from the day (Saturday or Sunday: weekend, a festival in the
            // holiday calendar: festival / holiday, any other day: night shift), see AdditionalHoursController::reasonFor().
            // From and To are no longer asked either: the claim is a quarter, half or full day (see 'portion').
            // ->add('reason', ChoiceType::class, [
            // 'label' => 'Worked on',
            // 'translation_domain' => false,
            // 'choices' => array_flip(AdditionalHours::REASONS),
            // 'expanded' => true,
            // 'label_attr' => ['class' => 'radio-custom'],
            // 'constraints' => [new NotBlank(message: 'Say why you worked that day.')],
            // ])
            // // the time worked, so that the same or overlapping hours cannot be claimed twice
            // ->add('fromTime', TimePickerType::class, [
            // 'label' => 'From',
            // 'translation_domain' => false,
            // 'attr' => ['class' => 'additional-hours-from'],
            // 'constraints' => [new NotNull(message: 'Enter the time you started.')],
            // ])
            // ->add('toTime', TimePickerType::class, [
            // 'label' => 'To',
            // 'translation_domain' => false,
            // 'attr' => ['class' => 'additional-hours-to'],
            // 'constraints' => [new NotNull(message: 'Enter the time you finished.')],
            // 'help' => 'In steps of 15 minutes. For a night shift past midnight, a "To" before the "From" is on the next day.',
            // ])
            // // the hours are worked out from "From" and "To"
            // ->add('hours', NumberType::class, [
            // 'label' => 'Extra hours',
            // 'translation_domain' => false,
            // 'scale' => 2,
            // 'html5' => true,
            // 'required' => false,
            // // 'attr' => ['min' => 0.25, 'max' => 24, 'step' => 0.25, 'placeholder' => 'For example 4.5', 'class' => 'additional-hours-input'],
            // // 'constraints' => [new NotNull(message: 'Enter the hours you worked.')],
            // 'attr' => ['readonly' => 'readonly', 'tabindex' => -1, 'placeholder' => 'Worked out from From and To', 'class' => 'additional-hours-input'],
            // 'help' => 'A day is ' . AdditionalHours::DAY_HOURS . ' hours: ' . (AdditionalHours::DAY_HOURS / 4) . ' h is a quarter day, ' . (AdditionalHours::DAY_HOURS / 2) . ' h a half day, ' . AdditionalHours::DAY_HOURS . ' h a full day.',
            // ])
            // how much is claimed: a quarter, half or full day of DAY_HOURS hours
            ->add('portion', ChoiceType::class, [
                'label' => 'Extra hours',
                'translation_domain' => false,
                'mapped' => false,
                'choices' => self::portionChoices(),
                'expanded' => true,
                'label_attr' => ['class' => 'radio-custom'],
                'constraints' => [new NotBlank(message: 'Choose a quarter, half or full day.')],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Work done',
                'translation_domain' => false,
                'required' => true,
                'attr' => ['rows' => 3, 'maxlength' => 1000, 'placeholder' => 'What you worked on, for example: HMEL go-live support'],
                'constraints' => [new NotBlank(message: 'Say what you worked on.'), new Length(max: 1000)],
            ])
        ;
    }

    /** a quarter, half or full day => what it is worth in days */
    public const PORTIONS = ['quarter' => 0.25, 'half' => 0.5, 'full' => 1.0];

    /**
     * @return array<string, string> label => key, for example "Half day (0.5 day, 4.5 hours)" => 'half'
     */
    public static function portionChoices(): array
    {
        $labels = ['quarter' => 'Quarter day', 'half' => 'Half day', 'full' => 'Full day'];
        $choices = [];
        foreach (self::PORTIONS as $key => $days) {
            $choices[$labels[$key] . ' (' . $days . ' day, ' . ($days * AdditionalHours::DAY_HOURS) . ' hours)'] = $key;
        }

        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdditionalHours::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'additional_hours',
        ]);
    }
}
