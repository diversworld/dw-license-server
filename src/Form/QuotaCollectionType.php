<?php
namespace App\Form;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface, CallbackTransformer};
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class QuotaCollectionType extends AbstractType
{
    public function getParent(): string { return CollectionType::class; }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['entry_type' => QuotaEntryType::class, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false]); }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (?array $quotas): array { $rows = []; foreach ($quotas ?? [] as $feature => $limit) { $rows[$feature] = ['feature' => $feature, 'limit' => $limit]; } return $rows; },
            static function (?array $rows): array { $quotas = []; foreach ($rows ?? [] as $row) { $feature = $row['feature'] ?? ''; if (isset($quotas[$feature])) { throw new TransformationFailedException('Duplicate quota feature.'); } $quotas[$feature] = $row['limit'] ?? 0; } return $quotas; },
        ));
    }
}
