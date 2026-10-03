<?php

namespace App\Controller\Admin;

use App\Entity\{Customer, License, Product};
use App\Service\ArchiveService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_VIEWER')]
class ArchiveController extends AbstractController
{
    #[Route('/admin/{_locale}/records/{type}/{id}/{action}', name: 'admin_record_archive', requirements: ['_locale' => 'de|en|fr|es', 'type' => 'customer|product|license', 'action' => 'archive|restore'], methods: ['GET', 'POST'])]
    public function change(string $type, string $id, string $action, Request $request, EntityManagerInterface $em, ArchiveService $archive, TranslatorInterface $translator): Response
    {
        $class = match ($type) { 'customer' => Customer::class, 'product' => Product::class, 'license' => License::class };
        $entity = Uuid::isValid($id) ? $em->find($class, Uuid::fromString($id)) : null;
        if (!$entity) {
            throw $this->createNotFoundException();
        }
        $restore = $action === 'restore';
        $this->denyAccessUnlessGranted($restore ? 'RECORD_RESTORE' : 'RECORD_ARCHIVE', $entity);
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'record_'.$action.'_'.$id])->add('reason', TextareaType::class, ['label' => 'license_action.reason', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 1000)]])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $archive->change($entity, $restore, $form->get('reason')->getData());
                $this->addFlash('success', $translator->trans('archive.saved'));

                return $this->redirectToRoute('admin_'.$type.'_index', ['_locale' => $request->getLocale()]);
            } catch (\DomainException $error) {
                $form->addError(new FormError($translator->trans($error->getMessage())));
            }
        }

        return $this->render('admin/archive/change.html.twig', ['entity' => $entity, 'type' => $type, 'action' => $action, 'form' => $form]);
    }
}
