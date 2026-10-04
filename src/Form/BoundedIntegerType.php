<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BoundedIntegerType extends AbstractType
{
    public function getParent(): string
    {
        return IntegerType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['minimum' => 0, 'maximum' => 1000000, 'required' => true, 'invalid_message' => 'validation.number_bounds']);
        $resolver->setAllowedTypes('minimum', 'int');
        $resolver->setAllowedTypes('maximum', 'int');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Reject invalid input before mapping to strict entity setters.
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $value): ?int => $value,
            static function (?int $value) use ($options): int {
                if ($value === null || $value < $options['minimum'] || $value > $options['maximum']) {
                    throw new TransformationFailedException('A number within the configured bounds is required.');
                }

                return $value;
            },
        ));
    }
}
