<?php

namespace App\Controller;

use App\Service\ServerDiagnostics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, ServerDiagnostics $diagnostics): Response
    {
        $response = $this->render('dashboard/index.html.twig', [
            'report' => $diagnostics->report(),
            'request' => $request,
            'sessionCount' => $request->getSession()->get('test_count', 0),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(ServerDiagnostics $diagnostics): JsonResponse
    {
        $report = $diagnostics->report();
        return $this->json($report, $report['status'] === 'ok' ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    #[Route('/session', name: 'session_test', methods: ['POST'])]
    public function session(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('session_test', $request->request->getString('_token'))) {
            throw new HttpException(403, 'Invalid session test token. Reload and try again.');
        }
        $session = $request->getSession();
        $session->set('test_count', $session->get('test_count', 0) + 1);
        return $this->redirectToRoute('dashboard', [], Response::HTTP_SEE_OTHER);
    }
}
