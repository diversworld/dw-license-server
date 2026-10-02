<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class AuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('Audit Logs')
            ->setEntityLabelInSingular('Audit Log')
            ->setDefaultSort([
                'createdAt' => 'DESC',
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->hideOnForm();

        yield TextField::new('eventType');

        yield TextField::new('entityType');

        yield TextField::new('entityIdentifier');

        yield TextField::new('entityId');

        yield TextareaField::new('message');

        yield AssociationField::new('performedBy');

        yield TextField::new('ipAddress');

        yield TextField::new('previousHash')
            ->hideOnIndex();

        yield TextField::new('entryHash')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt');
    }
}