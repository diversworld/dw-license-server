<?php

namespace App\Controller\Admin;

use App\Entity\License;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class LicenseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return License::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE)->add(Crud::PAGE_INDEX, Action::new('issue', 'Installation zuweisen / Token')->linkToRoute('admin_license_issue', fn (License $license) => ['id' => (string) $license->getId()]));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('customer', 'Kunde'),
            AssociationField::new('product', 'Modul'),
            TextField::new('licenseKey', 'Lizenzschlüssel')->hideOnIndex()->setFormTypeOption('disabled', true),
            ChoiceField::new('status')->setChoices(['Aktiv' => 'active', 'Gesperrt' => 'suspended', 'Widerrufen' => 'revoked']),
            ChoiceField::new('mode', 'Modus')->setChoices(['Online' => 'online', 'Offline' => 'offline']),
            IntegerField::new('maxDomains', 'Max. Installationen'),
            ArrayField::new('features', 'Features')->setHelp('Für das Contao Issue Service Bundle ist ein Eintrag mit dem Wert sla erforderlich.'),
            DateTimeField::new('expiresAt', 'Gültig bis')->setRequired(false),
            TextareaField::new('notes', 'Notizen'),
            DateTimeField::new('lastValidationAt', 'Letzte Prüfung')->hideOnForm(),
        ];
    }
}
