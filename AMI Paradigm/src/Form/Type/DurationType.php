<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Type;

use App\Utils\Duration;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Form\DataTransformer\DurationStringToSecondsTransformer;
use App\Validator\Constraints\Duration as DurationConstraint;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Custom form field type to handle duration strings.
 */
final class DurationType extends AbstractType
{
    /**
     * Plain integers are interpreted as hours.
     */
    public const PARSE_MODE_DEFAULT = 'default';
    /**
     * Plain integers below 10 are interpreted as hours, all others as minutes.
     */
    public const PARSE_MODE_INTEGER_MINUTES = 'integer_minutes';

    /** A time entry cannot be longer than this many hours (the hours box in the form uses the same limit) */
    public const MAX_ENTRY_HOURS = 10;

    /**
     * Server-side check for the limit above: the hours box in the browser stops at the limit too,
     * but a typed or tampered value has to be refused here as well.
     */
    public static function maxEntryHours(): Constraint
    {
        return new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
            if ($value === null || $value === '') {
                return;
            }
            try {
                $seconds = is_numeric($value) ? (int) $value : (new Duration())->parseDurationString((string) $value);
            } catch (\Exception) {
                return; // not a duration at all: reported by the duration check itself
            }
            if ($seconds > self::MAX_ENTRY_HOURS * 3600) {
                $context->buildViolation('The duration cannot be more than ' . self::MAX_ENTRY_HOURS . ' hours.')->addViolation();
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'duration',
            'constraints' => [new DurationConstraint()],
            'preset_hours' => null,
            'preset_minutes' => null,
            'toggle' => false,
            'max_hours' => 24,
            'icon' => 'duration',
            // how the frontend interprets user input, see PARSE_MODE_* constants
            'parse_mode' => self::PARSE_MODE_DEFAULT,
            'documentation' => [
                'type' => 'string',
                'description' => 'Duration - supports various formats: https://www.kimai.org/documentation/duration-format.html',
                'example' => '01:30',
            ]
        ]);
        $resolver->setAllowedTypes('max_hours', 'int');
        $resolver->setAllowedValues('parse_mode', [self::PARSE_MODE_DEFAULT, self::PARSE_MODE_INTEGER_MINUTES]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $class = 'duration-input';
        if (isset($view->vars['attr']['class'])) {
            $class .= ' ' . $view->vars['attr']['class'];
        }
        $view->vars['attr']['class'] = $class;
        $view->vars['attr']['autocomplete'] = 'off';
        $view->vars['attr']['data-duration-mode'] = $options['parse_mode'];
        // allows the frontend to detect invalid values, using the same rules as the server-side validation
        if (!isset($view->vars['attr']['pattern'])) {
            $view->vars['attr']['pattern'] = (new DurationConstraint())->getHtmlPattern();
        }
        $view->vars['toggle'] = $options['toggle'];

        if ($options['preset_hours'] !== null && $options['preset_minutes'] !== null) {
            $intervalMinutes = (int) $options['preset_minutes'];
            $maxHours = (int) $options['preset_hours'];

            if ($intervalMinutes < 1 || $maxHours < 1) {
                return;
            }

            // we track times for humans and no entry should ever be that long
            if (\is_int($options['max_hours']) && $maxHours > $options['max_hours']) {
                $maxHours = $options['max_hours'];
            }

            $maxMinutes = $maxHours * 60;
            $presets = [];

            for ($minutes = $intervalMinutes; $minutes <= $maxMinutes; $minutes += $intervalMinutes) {
                $h = (int) ($minutes / 60);
                $m = $minutes % 60;
                $interval = new \DateInterval('PT' . $h . 'H' . $m . 'M');
                $presets[] = $interval->format('%h:%I');
            }

            $view->vars['duration_presets'] = $presets;
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new DurationStringToSecondsTransformer());
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
