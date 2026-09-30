<?php

namespace App\Controller\Admin;

use App\Entity\Customer;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField, FormField};
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
		yield FormField::addFieldset('Persönliche Daten')
			->setIcon('fa fa-user');

		yield TextField::new('company', 'Firma')
			->setColumns(12);

		yield TextField::new('firstname', 'Vorname')
			->setColumns(6);

		yield TextField::new('lastname', 'Nachname')
			->setColumns(6);

		yield EmailField::new('email', 'E-Mail-Adresse')
			->setColumns(12)
			->setHelp(
			'Diese E-Mail-Adresse wird auch für die Anmeldung verwendet.'
		);

		yield FormField::addFieldset('Anschrift')
			->setIcon('fa fa-address-card');

		yield TextField::new('street', 'Straße')
			->setColumns(9);

		yield TextField::new('zip', 'PLZ')
			->setColumns(4);

		yield TextField::new('city', 'Wohnort')
			->setColumns(8);

		yield FormField::addFieldset('Kontaktdaten')
			->setIcon('fa fa-phone');

		yield TextField::new('mobile', 'Mobil')
			->setColumns(6);

		yield TextField::new('phone', 'Telefon')
			->setColumns(6);
		yield TextField::new('country', 'Land')
			->setColumns(6);

		yield TextField::new('vatId', 'USt-ID')
			->setColumns(6);

		yield BooleanField::new('active', 'Aktiv')
			->setColumns(6);		
    }
}
