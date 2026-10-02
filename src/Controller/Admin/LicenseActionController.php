<?php

namespace App\Controller\Admin;

use App\Entity\{License, LicenseAction};
use App\Service\LicenseLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{DateTimeType, TextareaType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_VIEWER')]
class LicenseActionController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LicenseLifecycle $lifecycle, private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/admin/licenses/{id}/history', name: 'admin_license_history_legacy', methods: ['GET'])]
    #[Route('/admin/{_locale}/licenses/{id}/history', name: 'admin_license_history', defaults: ['_locale' => 'de'], requirements: ['_locale' => 'de|en|fr|es'], methods: ['GET'])]
    public function history(License $license): Response
    {
        return $this->render('admin/license/action.html.twig', ['license' => $license, 'action' => null, 'form' => null, 'history' => $this->historyEntries($license)]);
    }

    #[Route('/admin/{_locale}/licenses/{id}/actions/{action}', name: 'admin_license_action', defaults: ['_locale' => 'de'], requirements: ['action' => 'renew|pause|revoke|reactivate'], methods: ['GET', 'POST'])]
    #[Route('/admin/licenses/{id}/actions/{action}', name: 'admin_license_action_legacy', requirements: ['action' => 'renew|pause|revoke|reactivate'], methods: ['GET', 'POST'])]
    public function perform(License $license, string $action, Request $request): Response
    {
        $this->denyAccessUnlessGranted('LICENSE_'.strtoupper($action));
        $builder = $this->createFormBuilder()->add('reason', TextareaType::class, ['label' => 'license_action.reason', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 1000)]]);
        if ($action === 'renew') {
            $builder->add('expiresAt', DateTimeType::class, ['label' => 'license_action.expires_at', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]]);
        }
        $form = $builder->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->lifecycle->perform($license, $action, $form->get('reason')->getData(), $action === 'renew' ? $form->get('expiresAt')->getData() : null);
                $this->addFlash('success', $this->translator->trans('license_action.saved'));

                return $this->redirectToRoute('admin_license_history', ['id' => (string) $license->getId(), '_locale' => $request->getLocale()]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($this->translator->trans($exception->getMessage())));
            }
        }

        return $this->render('admin/license/action.html.twig', ['license' => $license, 'action' => $action, 'form' => $form, 'history' => $this->historyEntries($license)]);
    }

    private function historyEntries(License $license): array
    {
        return $this->em->getRepository(LicenseAction::class)->findBy(['license' => $license], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
