<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route('/legacy/mpesa')]
class LegacyMpesaController extends AbstractController
{
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /* ================= LOGGER ================= */

    private function legacyLog($message)
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/legacyMpesa.log';

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

        file_put_contents($logFile, $line, FILE_APPEND);
    }

    /* ================= DASHBOARD ================= */

    #[Route('/dashboard', name: 'mpesa_dashboard')]
    public function dashboard(): Response
    {
        $this->legacyLog("Dashboard route accessed");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['admin_logged_in'])) {
            $this->legacyLog("User not logged in → redirect to login");

            return $this->redirectToRoute('mpesa_login');
        }

        $isLocked = $_SESSION['user_locked'] ?? false;

        return $this->render('mpesa/dashboard.html.twig', [
            'is_locked' => $isLocked
        ]);
    }

    /* ================= LOGIN ================= */

    #[Route('/login', name: 'mpesa_login')]
    public function login(): Response
    {
        $this->legacyLog("Login route hit");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $path = $this->getParameter('kernel.project_dir')
            . '/legacy/mpesa/login.php';

        $this->legacyLog("Loading login file: " . $path);

        if (!file_exists($path)) {
            $this->legacyLog("LOGIN FILE NOT FOUND");
            throw $this->createNotFoundException();
        }

        ob_start();
        include $path;
        $content = ob_get_clean();

        return new Response($content);
    }

    /* ================= AJAX HANDLER ================= */

    #[Route('/ajax/{file}', name: 'mpesa_ajax')]
    public function ajax(string $file): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $file = basename($file);

        $path = $this->getParameter('kernel.project_dir')
            . '/legacy/mpesa/ajax/' . $file;

        $this->legacyLog("AJAX request: " . $file);

        if (!file_exists($path)) {
            $this->legacyLog("AJAX FILE NOT FOUND: " . $file);
            throw $this->createNotFoundException();
        }

        ob_start();
        include $path;
        $content = ob_get_clean();

        return new Response($content);
    }
}
