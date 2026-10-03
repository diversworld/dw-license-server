<?php
namespace App\Form;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{TextType, IntegerType};
use Symfony\Component\Validator\Constraints as Assert;
final class QuotaEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('feature', TextType::class, ['label' => 'entitlements.feature', 'constraints' => [new Assert\NotBlank()]])->add('limit', IntegerType::class, ['label' => 'entitlements.quota', 'constraints' => [new Assert\Positive(), new Assert\LessThanOrEqual(1000000000)]]);
    }
}
