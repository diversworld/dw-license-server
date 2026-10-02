<?php

namespace App\Controller\Admin;

use App\Entity\License;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{ArrayField, AssociationField, BooleanField, ChoiceField, DateTimeField, EmailField, IntegerField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class LicenseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return License::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::DELETE)
            ->setPermission(Action::NEW, 'LICENSE_CREATE')
            ->setPermission(Action::EDIT, 'LICENSE_EDIT')
            ->add(Crud::PAGE_INDEX, Action::new('issue', 'Installation zuweisen / Token')->linkToRoute('admin_license_issue', fn (License $license) => ['id' => (string) $license->getId()]))
            ->setPermission('issue', 'LICENSE_ISSUE')
            ->add(Crud::PAGE_INDEX, Action::new('history', 'license_action.history')->linkToRoute('admin_license_history', fn (License $license) => ['id' => (string) $license->getId()]));
        foreach (\App\Service\LicenseLifecycle::ACTIONS as $name) {
            $action = Action::new($name, 'license_action.'.$name)
                ->linkToRoute('admin_license_action', fn (License $license) => ['id' => (string) $license->getId(), 'action' => $name]);
            $actions->add(Crud::PAGE_INDEX, $action)->setPermission($name, 'LICENSE_'.strtoupper($name));
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('customer', 'Kunde'),
            AssociationField::new('product', 'Modul'),
            TextField::new('licenseKey', 'Lizenzschlüssel')->hideOnIndex()->setFormTypeOption('disabled', true),
            ChoiceField::new('status')->hideOnForm()->setChoices(['Aktiv' => 'active', 'Gesperrt' => 'suspended', 'Widerrufen' => 'revoked']),
            ChoiceField::new('mode', 'Modus')->setChoices(['Online' => 'online', 'Offline' => 'offline']),
            IntegerField::new('maxDomains', 'Max. Installationen'),
            ArrayField::new('features', 'Features')->setHelp('Für das Contao Issue Service Bundle ist ein Eintrag mit dem Wert sla erforderlich.'),
            DateTimeField::new('expiresAt', 'Gültig bis')->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW),
            TextareaField::new('notes', 'Notizen'),
            DateTimeField::new('lastValidationAt', 'Letzte Prüfung')->hideOnForm(),
        ];
    }
	
	public function configureCrud(Crud $crud): Crud
	{
		return $crud
			->setEntityLabelInSingular('Lizenz')
			->setEntityLabelInPlural('Lizenzen')
            ->overrideTemplates([
                'crud/index' => 'admin/license/index.html.twig',
            ]);
	}
}
