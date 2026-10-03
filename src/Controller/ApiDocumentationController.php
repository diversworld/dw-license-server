<?php

namespace App\Controller;

use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

class ApiDocumentationController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'nelmio_api_doc.render_docs')]
        private readonly RenderOpenApi $renderer,
    ) {
    }

    #[Route('/api/doc', name: 'app.swagger_ui', methods: ['GET'])]
    public function ui(Request $request): Response
    {
        return new Response($this->renderer->renderFromRequest($request, RenderOpenApi::HTML, 'default', ['ui_renderer' => 'swaggerui']), headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[Route('/api/doc.json', name: 'app.swagger', methods: ['GET'])]
    public function specification(Request $request): Response
    {
        return new Response($this->renderer->renderFromRequest($request, RenderOpenApi::JSON, 'default'), headers: ['Content-Type' => 'application/json']);
    }
}
