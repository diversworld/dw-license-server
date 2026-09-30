<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('slug', 'Modulkennung'),
            TextField::new('name', 'Name'),
            TextareaField::new('description', 'Beschreibung'),
            TextField::new('currentVersion', 'Version'),
            BooleanField::new('active', 'Aktiv'),
        ];
    }
}
