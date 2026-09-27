<?php

namespace App\Controller\Admin;

use App\Entity\Customer;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CustomerCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Customer::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('company', 'Firma'),
            TextField::new('firstname', 'Vorname'),
            TextField::new('lastname', 'Nachname'),
            EmailField::new('email'),
            TextField::new('street', 'Straße'),
            TextField::new('zip', 'Postleitzahl'),
            TextField::new('city', 'Ort'),
            TextField::new('country', 'Land'),
            TextField::new('vatId', 'USt-ID'),
            BooleanField::new('active', 'Aktiv'),
        ];
    }
}
