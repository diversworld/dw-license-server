<?php

namespace App\Controller\Admin;

use App\Dto\ActivationRequest;
use App\Entity\License;
use App\Form\InstallationType;
use App\Service\LicenseManager;
use App\Service\LicenseSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('LICENSE_ISSUE')]
class IssueLicenseController extends AbstractController
{
    #[Route('/admin/licenses/{id}/issue', name: 'admin_license_issue', methods: ['GET', 'POST'])]
    public function __invoke(License $license, Request $request, LicenseManager $manager, LicenseSigner $signer, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        $form = $this->createForm(InstallationType::class)->handleRequest($request);
        $token = null;
        $publicKey = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $token = $manager->activate(new ActivationRequest($license->getLicenseKey(), $license->getProduct()->getSlug(), $data['tenant'], $data['domain']));
                $publicKey = json_encode($signer->publicKeys(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (HttpException $e) {
                $form->addError(new FormError($translator->trans($e->getMessage())));
            }
        }
        $response = $this->render('admin/issue.html.twig', ['form' => $form, 'license' => $license, 'token' => $token, 'public_key' => $publicKey]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
