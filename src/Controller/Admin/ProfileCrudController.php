<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class ProfileCrudController extends AbstractCrudController
{
    public function __construct(private readonly \App\Service\ProfileImageUpload $images)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('PROFILE_SELF')
            ->setPageTitle(Crud::PAGE_EDIT, 'Mein Profil')
            ->setEntityLabelInSingular('Profil')
            ->setEntityLabelInPlural('Profile');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(
                Action::INDEX,
                Action::NEW,
                Action::DELETE,
                Action::SAVE_AND_ADD_ANOTHER
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Persönliche Daten')
            ->setIcon('fa fa-user');

        yield TextField::new('firstname', 'Vorname')
            ->setColumns(6);

        yield TextField::new('lastname', 'Nachname')
            ->setColumns(6);

        yield EmailField::new('email', 'E-Mail-Adresse')
            ->setColumns(12)
            ->setHelp(
                'Diese E-Mail-Adresse wird auch für die Anmeldung verwendet.'
            );

        yield ImageField::new('profileImage', 'Profilbild')
            ->setBasePath('/uploads/profile')
            ->setUploadDir('public/uploads/profile')
            ->setUploadedFileNamePattern($this->images->filename(...))
            ->setFileConstraints($this->images->constraint())
            ->setFormTypeOption('upload_new', $this->images->store(...))
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('JPEG, PNG oder WebP; maximal 5 MB; 16–4096 Pixel je Seite, maximal 16 Megapixel.')
            ->setFormTypeOption('attr', ['accept' => 'image/jpeg,image/png,image/webp']);
        
        yield FormField::addFieldset('Anschrift')
            ->setIcon('fa fa-address-card');

        yield TextField::new('street', 'Straße')
            ->setColumns(9);

        yield TextField::new('postalCode', 'PLZ')
            ->setColumns(4);

        yield TextField::new('city', 'Wohnort')
            ->setColumns(8);

        yield FormField::addFieldset('Kontaktdaten')
            ->setIcon('fa fa-phone');

        yield TextField::new('mobile', 'Mobil')
            ->setColumns(6);

        yield TextField::new('phone', 'Telefon')
            ->setColumns(6);
    }

    protected function getRedirectResponseAfterSave(
        AdminContext $context,
        string $action
    ): RedirectResponse {
        // Die Profilverwaltung hat keine Indexseite. Die Profilroute
        // ermittelt stattdessen die Bearbeitungsseite des angemeldeten Benutzers.
        return $this->redirectToRoute('dashboard_profile');
    }

    public function updateEntity(
        EntityManagerInterface $entityManager,
        $entityInstance
    ): void {
        if (!$entityInstance instanceof User) {
            return;
        }

        // Sicherheitsprüfung:
        // Über diesen Controller darf nur das eigene Profil
        // verändert werden.
        if (!$this->getUser() instanceof User || (string) $entityInstance->getId() !== (string) $this->getUser()->getId()) {
            throw $this->createAccessDeniedException(
                'Du darfst nur dein eigenes Profil bearbeiten.'
            );
        }

        $entityInstance->setUpdatedAt(
            new \DateTimeImmutable()
        );

        parent::updateEntity(
            $entityManager,
            $entityInstance
        );
    }
}
