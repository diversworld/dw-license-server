<?php

namespace App\Controller\Admin;

use App\Entity\Activation;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class ActivationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Activation::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::NEW)->setPermission(Action::EDIT, 'ACTIVATION_MANAGE');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('license', 'ui.license')->hideOnForm(),
            TextField::new('tenant', 'ui.tenant')->hideOnForm(),
            TextField::new('domain', 'ui.domain')->hideOnForm(),
            BooleanField::new('active', 'ui.active')->renderAsSwitch(false),
            DateTimeField::new('activatedAt', 'ui.activated')->hideOnForm(),
            DateTimeField::new('updatedAt', 'ui.last_checked')->hideOnForm(),
        ];
    }
}
