<?php
namespace App\Controller\Admin;
use App\Entity\LicensePlan;
use App\Form\QuotaCollectionType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, IntegerField, TextField};
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('ROLE_VIEWER')]
final class LicensePlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return LicensePlan::class; }
    public function configureCrud(Crud $crud): Crud { return $crud->setEntityLabelInSingular('entitlements.plan')->setEntityLabelInPlural('entitlements.plans'); }
    public function configureActions(Actions $actions): Actions { return $actions->disable(Action::DELETE)->setPermission(Action::NEW, 'PRODUCT_MANAGE')->setPermission(Action::EDIT, 'PRODUCT_MANAGE'); }
    public function configureFields(string $pageName): iterable
    {
        // Display choices must be flippable; forms keep the nullable boolean values.
        $updateChoices = \in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true)
            ? ['entitlements.unspecified' => null, 'entitlements.allowed_updates' => true, 'entitlements.no_updates' => false]
            : ['entitlements.allowed_updates' => 1, 'entitlements.no_updates' => 0];

        return [
            AssociationField::new('product', 'entitlements.product')->setHelp('form_help.product'),
            TextField::new('name', 'entitlements.name')->setHelp('form_help.name'),
            IntegerField::new('durationDays', 'entitlements.duration')->setHelp('form_help.duration'),
            IntegerField::new('maxDomains', 'entitlements.installations')->setHelp('form_help.installation_limit'),
            ArrayField::new('features', 'entitlements.allowed')->setHelp('form_help.features'),
            ArrayField::new('quotas', 'entitlements.quotas')->setFormType(QuotaCollectionType::class)->setHelp('form_help.quotas'),
            ChoiceField::new('updatesAllowed', 'entitlements.updates')->setChoices($updateChoices)->setHelp('form_help.updates'),
            BooleanField::new('active', 'entitlements.active')->setHelp('form_help.active'),
        ];
    }
}
