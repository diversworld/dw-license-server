<?php

namespace App\Controller;

use App\Dto\ActivationRequest;
use App\Entity\{Activation, License, LicenseAction, User};
use App\Service\{LicenseManager, PortalDomainChange};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_CUSTOMER')]
#[Route('/portal/{_locale}', requirements: ['_locale' => 'de|en|fr|es'], defaults: ['_locale' => 'de'])]
final class CustomerPortalController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator) {}
    private function assertPortal(): void
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->hasGlobalAccess() || $user->getCustomers()->isEmpty()) { throw $this->createAccessDeniedException(); }
    }
    #[Route('', name: 'portal_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertPortal();
        return $this->render('portal/index.html.twig', ['licenses' => $this->em->getRepository(License::class)->findBy(['deletedAt' => null])], new Response(headers: ['Cache-Control' => 'no-store']));
    }
    #[Route('/licenses/{id}', name: 'portal_license', methods: ['GET'])]
    public function detail(License $license): Response
    {
        $this->assertPortal(); $this->denyAccessUnlessGranted('PORTAL_DOWNLOAD', $license);
        return $this->render('portal/license.html.twig', ['license' => $license, 'installations' => $this->em->getRepository(Activation::class)->findBy(['license' => $license]), 'history' => $this->em->getRepository(LicenseAction::class)->findBy(['license' => $license], ['createdAt' => 'DESC'])], new Response(headers: ['Cache-Control' => 'no-store']));
    }
    #[Route('/licenses/{id}/download', name: 'portal_download', methods: ['GET', 'POST'])]
    #[RateLimit('portal_sensitive')]
    public function download(License $license, Request $request, LicenseManager $manager): Response
    {
        $this->assertPortal(); $this->denyAccessUnlessGranted('PORTAL_DOWNLOAD', $license);
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'portal_download_'.$license->getId()])->add('installation', EntityType::class, ['class' => Activation::class, 'choices' => $this->em->getRepository(Activation::class)->findBy(['license' => $license, 'active' => true]), 'choice_label' => 'domain', 'label' => 'portal.installation', 'constraints' => [new Assert\NotNull()]])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $activation = $form->get('installation')->getData();
            try {
                $token = $manager->activate(new ActivationRequest($license->getLicenseKey(), $license->getProduct()->getSlug(), $activation->getTenant(), $activation->getDomain()));
                return new Response($token."\n", headers: ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="license-'.$license->getId().'.lic"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $form->addError(new FormError($error->getMessage())); }
        }
        return $this->render('portal/form.html.twig', ['form' => $form, 'license' => $license, 'title' => 'portal.download'], new Response(status: $form->isSubmitted() ? 422 : 200, headers: ['Cache-Control' => 'no-store']));
    }
    #[Route('/installations/{id}/domain', name: 'portal_domain', methods: ['GET', 'POST'])]
    #[RateLimit('portal_sensitive')]
    public function domain(Activation $activation, Request $request, PortalDomainChange $changes): Response
    {
        $this->assertPortal(); $this->denyAccessUnlessGranted('PORTAL_DOMAIN_CHANGE', $activation->getLicense());
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'portal_domain_'.$activation->getId()])->add('domain', TextType::class, ['label' => 'portal.domain', 'constraints' => [new Assert\NotBlank(), new Assert\Hostname(requireTld: true), new Assert\Length(max: 253)]])->add('reason', TextareaType::class, ['label' => 'license_action.reason', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 1000)]])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $changes->change($activation, $form->get('domain')->getData(), $form->get('reason')->getData());
                return $this->redirectToRoute('portal_license', ['id' => (string) $activation->getLicense()->getId(), '_locale' => $request->getLocale()]);
            } catch (\DomainException $error) { $form->addError(new FormError($this->translator->trans($error->getMessage()))); }
        }
        return $this->render('portal/form.html.twig', ['form' => $form, 'license' => $activation->getLicense(), 'title' => 'portal.change_domain'], new Response(status: $form->isSubmitted() ? 422 : 200, headers: ['Cache-Control' => 'no-store']));
    }
}
