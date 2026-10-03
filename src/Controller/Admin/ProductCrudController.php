<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class ProductCrudController extends AbstractCrudController
{
    use ArchiveActions;

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->archiveActions($actions->disable(Action::DELETE)->setPermission(Action::NEW, 'PRODUCT_MANAGE')->setPermission(Action::EDIT, 'PRODUCT_MANAGE'));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            BooleanField::new('archived', 'archive.archived')->hideOnForm()->renderAsSwitch(false),
            DateTimeField::new('deletedAt', 'archive.date')->hideOnForm(),
            TextField::new('slug', 'Modulkennung'),
            TextField::new('name', 'Name'),
            TextareaField::new('description', 'Beschreibung'),
            TextField::new('currentVersion', 'Version'),
            BooleanField::new('active', 'Aktiv'),
            ArrayField::new('allowedFeatures', 'entitlements.allowed'),
            ArrayField::new('requiredFeatures', 'entitlements.required'),
            ArrayField::new('featureQuotas', 'entitlements.quotas')->setFormType(\App\Form\QuotaCollectionType::class),
            IntegerField::new('maxInstallations', 'entitlements.installations')->setFormType(\App\Form\BoundedIntegerType::class)->setFormTypeOptions(['minimum' => 1, 'maximum' => 1000000]),
            IntegerField::new('tokenLifetimeSeconds')->setFormType(\App\Form\BoundedIntegerType::class)->setFormTypeOptions(['minimum' => 60, 'maximum' => 31536000])->setHelp('policy.token_help'),
            IntegerField::new('gracePeriodSeconds')->setFormType(\App\Form\BoundedIntegerType::class)->setFormTypeOptions(['minimum' => 0, 'maximum' => 31536000])->setHelp('policy.grace_help'),
        ];
    }
}
