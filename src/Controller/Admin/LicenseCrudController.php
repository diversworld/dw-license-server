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
    use ArchiveActions;

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
                ->displayIf(static fn (License $license) => !$license->isArchived()
                    && isset(\App\Service\LicenseTransitions::TARGETS[$name][$license->getStatus()])
                    && ($name === 'renew' || \App\Service\LicenseTransitions::TARGETS[$name][$license->getStatus()] !== 'active' || !$license->isExpired()))
                ->linkToRoute('admin_license_action', fn (License $license) => ['id' => (string) $license->getId(), 'action' => $name]);
            $actions->add(Crud::PAGE_INDEX, $action)->setPermission($name, 'LICENSE_'.strtoupper($name));
        }

        return $this->archiveActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            BooleanField::new('archived', 'archive.archived')->hideOnForm()->renderAsSwitch(false),
            DateTimeField::new('deletedAt', 'archive.date')->hideOnForm(),
            AssociationField::new('customer', 'Kunde'),
            AssociationField::new('product', 'Modul'),
            TextField::new('licenseKey', 'Lizenzschlüssel')->hideOnIndex()->setFormTypeOption('disabled', true),
            ChoiceField::new('status')->hideOnForm()->setChoices(['Aktiv' => 'active', 'Gesperrt' => 'suspended', 'Widerrufen' => 'revoked']),
            ChoiceField::new('mode', 'Modus')->setChoices(['Online' => 'online', 'Offline' => 'offline']),
            IntegerField::new('maxDomains', 'Max. Installationen'),
            ArrayField::new('features', 'entitlements.allowed')->setHelp('entitlements.features_help'),
            ArrayField::new('quotas', 'entitlements.quotas')->setFormType(\App\Form\QuotaCollectionType::class),
            ChoiceField::new('updatesAllowed', 'entitlements.updates')->hideOnForm()->setChoices(['entitlements.unspecified' => null, 'entitlements.allowed_updates' => true, 'entitlements.no_updates' => false]),
            DateTimeField::new('updatesUntil', 'entitlements.updates_until')->hideOnForm(),
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
