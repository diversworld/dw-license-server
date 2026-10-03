<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactorManagement;
use App\Service\TwoFactorQrCode;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\{PasswordType, TextType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TwoFactorManagementController extends AbstractController
{
    public function __construct(private readonly \App\Security\PendingTotpEnrollment $enrollment) {}
    #[Route('/security/2fa/manage', name: 'two_factor_manage', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(): Response
    {
        return $this->render('security/two_factor_manage.html.twig', ['operation' => null, 'codes' => [], 'form' => null, 'secret' => null, 'qrCode' => null], new Response(headers: ['Cache-Control' => 'no-store']));
    }

    #[Route('/security/2fa/manage/{operation}', name: 'two_factor_change', requirements: ['operation' => 'rotate|codes'], methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RateLimit('two_factor')]
    public function change(string $operation, Request $request, TwoFactorManagement $management, TotpAuthenticatorInterface $totp, TwoFactorQrCode $qr, Security $security, TranslatorInterface $translator): Response
    {
        return $this->form($operation, $request, $management, $totp, $qr, $security, $translator);
    }

    #[Route('/security/2fa/recover', name: 'two_factor_recover', methods: ['GET', 'POST'])]
    #[RateLimit('two_factor')]
    public function recover(Request $request, TwoFactorManagement $management, TotpAuthenticatorInterface $totp, TwoFactorQrCode $qr, Security $security, TranslatorInterface $translator): Response
    {
        if (!$this->isGranted('IS_AUTHENTICATED_FULLY') && !$this->isGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS')) { throw $this->createAccessDeniedException(); }

        return $this->form('recover', $request, $management, $totp, $qr, $security, $translator);
    }

    private function form(string $operation, Request $request, TwoFactorManagement $management, TotpAuthenticatorInterface $totp, TwoFactorQrCode $qr, Security $security, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isTotpAuthenticationEnabled()) { throw $this->createAccessDeniedException(); }
        $secret = $qrCode = null;
        if ($operation === 'rotate') {
            $secret = $this->enrollment->secret($user, $request->getSession(), 'two_factor_replacement_secret');
            $pending = clone $user; $pending->enableTwoFactor($secret, []);
            $qrCode = $qr->dataUri($totp->getQRContent($pending));
        }
        $builder = $this->createFormBuilder()
            ->add('password', PasswordType::class, ['label' => 'two_factor.password', 'constraints' => [new NotBlank()]])
            ->add('proof', TextType::class, ['label' => $operation === 'recover' ? 'two_factor.recovery_code' : 'two_factor.current_proof', 'constraints' => [new NotBlank()], 'attr' => ['autocomplete' => 'one-time-code']]);
        if ($operation === 'rotate') { $builder->add('newCode', TextType::class, ['label' => 'two_factor.new_code', 'constraints' => [new NotBlank()], 'attr' => ['autocomplete' => 'one-time-code']]); }
        $form = $builder->getForm()->handleRequest($request);
        $codes = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $codes = match ($operation) {
                    'rotate' => $management->rotate($user, $data['password'], $data['proof'], $secret, $data['newCode']),
                    'codes' => $management->regenerateCodes($user, $data['password'], $data['proof']),
                    'recover' => (function () use ($management, $user, $data): array { $management->recover($user, $data['password'], $data['proof']); return []; })(),
                };
                $request->getSession()->remove('two_factor_replacement_secret');
                // All previous sessions, including this one, must authenticate again.
                $security->logout(false);
                if ($operation === 'recover') { return $this->redirectToRoute('app_login'); }
                $secret = $qrCode = null;
            } catch (\DomainException $error) { $form->addError(new FormError($translator->trans($error->getMessage()))); }
        }

        return $this->render('security/two_factor_manage.html.twig', ['operation' => $operation, 'codes' => $codes, 'form' => $form->createView(), 'secret' => $secret, 'qrCode' => $qrCode], new Response(headers: ['Cache-Control' => 'no-store']));
    }
}
