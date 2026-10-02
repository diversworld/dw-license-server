<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;

class TwoFactorController extends AbstractController
{
    #[Route('/2fa', name: '2fa_login', methods: ['GET'])]
    public function challenge(): never
    {
        throw new \LogicException('Handled by the two-factor firewall.');
    }

    #[Route('/2fa_check', name: '2fa_login_check', methods: ['POST'])]
    #[RateLimit('two_factor')]
    public function check(): never
    {
        throw new \LogicException('Handled by the two-factor firewall.');
    }

    #[Route('/security/2fa/setup', name: 'two_factor_setup', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_VIEWER')]
    #[RateLimit('two_factor')]
    public function setup(Request $request, TotpAuthenticatorInterface $totp, EntityManagerInterface $em, TokenStorageInterface $tokens): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('admin');
        }
        $session = $request->getSession();
        $secret = $session->get('two_factor_setup_secret') ?? $totp->generateSecret();
        $session->set('two_factor_setup_secret', $secret);
        $pending = clone $user;
        $pending->enableTwoFactor($secret, []);
        $form = $this->createFormBuilder()
            ->add('password', PasswordType::class, ['label' => 'two_factor.password', 'constraints' => [new NotBlank(), new UserPassword()]])
            ->add('code', TextType::class, ['label' => 'two_factor.code', 'constraints' => [new NotBlank()], 'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric']])
            ->getForm()->handleRequest($request);
        $codes = [];
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$totp->checkCode($pending, $form->get('code')->getData())) {
                $form->get('code')->addError(new \Symfony\Component\Form\FormError('two_factor.invalid_code'));
            } else {
                $codes = array_map(static fn (): string => bin2hex(random_bytes(10)), range(1, 10));
                $user->enableTwoFactor($secret, $codes);
                $em->flush();
                $session->remove('two_factor_setup_secret');
                $session->migrate(true);
                $tokens->getToken()->setAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE, true);
            }
        }

        return $this->render('security/two_factor_setup.html.twig', [
            'form' => $form->createView(), 'secret' => $codes === [] ? $secret : null,
            'provisioningUri' => $codes === [] ? $totp->getQRContent($pending) : null, 'codes' => $codes,
        ], new Response(headers: ['Cache-Control' => 'no-store']));
    }
}
