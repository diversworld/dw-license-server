<?php

namespace App\Controller\Admin;

use App\Archive\ArchivableInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud, Filters};
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;

trait ArchiveActions
{
    private function archiveActions(Actions $actions): Actions
    {
        foreach (['archive', 'restore'] as $operation) {
            $action = Action::new($operation, 'archive.'.$operation)
                ->displayIf(static fn (ArchivableInterface $entity) => $entity->isArchived() === ($operation === 'restore'))
                ->linkToRoute('admin_record_archive', fn (ArchivableInterface $entity) => [
                    'id' => (string) $entity->getId(), 'type' => match (true) { $entity instanceof \App\Entity\Customer => 'customer', $entity instanceof \App\Entity\Product => 'product', $entity instanceof \App\Entity\License => 'license' },
                    'action' => $operation, '_locale' => $this->container->get('request_stack')->getCurrentRequest()?->getLocale() ?? 'de',
                ]);
            $actions->add(Crud::PAGE_INDEX, $action)->setPermission($operation, $operation === 'restore' ? 'RECORD_RESTORE' : 'RECORD_ARCHIVE');
        }

        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(NullFilter::new('deletedAt', 'archive.archived')->setChoiceLabels('archive.current', 'archive.archived'));
    }
}
