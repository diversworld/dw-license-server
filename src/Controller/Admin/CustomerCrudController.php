<?php

namespace App\Controller\Admin;

use App\Entity\Customer;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, ImageField, IntegerField, TextField, TextareaField, FormField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class CustomerCrudController extends AbstractCrudController
{
    use ArchiveActions;

    public static function getEntityFqcn(): string
    {
        return Customer::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->archiveActions($actions->disable(Action::DELETE)->setPermission(Action::NEW, 'CUSTOMER_MANAGE')->setPermission(Action::EDIT, 'CUSTOMER_MANAGE')
            ->add(Crud::PAGE_INDEX, Action::new('api_credentials', 'api_credentials.title')->linkToRoute('admin_api_credentials', fn (Customer $customer) => ['id' => (string) $customer->getId(), '_locale' => $this->getContext()?->getRequest()->getLocale() ?? 'de']))->setPermission('api_credentials', 'API_CREDENTIAL_MANAGE')
            ->add(Crud::PAGE_INDEX, Action::new('webhooks', 'webhook.title')->linkToRoute('admin_webhooks', fn (Customer $customer) => ['id' => (string) $customer->getId(), '_locale' => $this->getContext()?->getRequest()->getLocale() ?? 'de']))->setPermission('webhooks', 'API_CREDENTIAL_MANAGE')); 
    }

    public function configureFields(string $pageName): iterable
    {
        yield BooleanField::new('archived', 'archive.archived')->hideOnForm()->renderAsSwitch(false);
        yield DateTimeField::new('deletedAt', 'archive.date')->hideOnForm();
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

		yield ImageField::new('logoImage', 'Logo')
			->setBasePath('/uploads/profile')
			->setUploadDir('public/uploads/profile')
			->setUploadedFileNamePattern('[uuid].[extension]')
			->setRequired(false)
			->setColumns(12)
			->setHelp('Profilbild als JPG, PNG oder WebP.')
			->setFormTypeOption('attr', [
				'accept' => 'image/jpeg,image/png,image/webp',
			]);

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

        yield ChoiceField::new('reminderLocale', 'expiry_reminder.locale')->setChoices(['Deutsch' => 'de', 'English' => 'en', 'Français' => 'fr', 'Español' => 'es']);
        yield ArrayField::new('reminderRecipients', 'expiry_reminder.recipients');
		yield BooleanField::new('active', 'Aktiv')
			->setColumns(6);		
    }
}
