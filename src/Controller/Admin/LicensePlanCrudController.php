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
        return [AssociationField::new('product', 'entitlements.product'), TextField::new('name', 'entitlements.name'), IntegerField::new('durationDays', 'entitlements.duration'), IntegerField::new('maxDomains', 'entitlements.installations'), ArrayField::new('features', 'entitlements.allowed'), ArrayField::new('quotas', 'entitlements.quotas')->setFormType(QuotaCollectionType::class), ChoiceField::new('updatesAllowed', 'entitlements.updates')->setChoices(['entitlements.unspecified' => null, 'entitlements.allowed_updates' => true, 'entitlements.no_updates' => false]), BooleanField::new('active', 'entitlements.active')];
    }
}
