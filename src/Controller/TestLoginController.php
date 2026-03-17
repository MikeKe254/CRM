<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ⚠️  DISPOSABLE TEST CONTROLLER — DELETE AFTER USE
 *
 * Visit: GET /dev/test-login
 * Attempts to log in Mike One and returns full session details.
 */
class TestLoginController extends AbstractController
{
    public function __construct(private readonly AuthService $auth) {}

    #[Route('/dev/test-login', name: 'dev_test_login', methods: ['GET'])]
    public function testLogin(Request $request): JsonResponse
    {
        try {
            $result = $this->auth->loginDashboard(
                subdomain:            'koma',
                email:                'mike1@angavu.test',
                password:             'Mike@132',
                ipAddress:            $request->getClientIp() ?? '',
                userAgent:            $request->headers->get('User-Agent') ?? '',
                deviceName:           'Test Browser',
                terminalIdentifier:   'test-terminal-001',
            );

            return $this->json([
                'status'  => 'success',
                'message' => 'Mike One logged in successfully.',
                'data'    => $result->toArray(),
            ]);

        } catch (AuthException $e) {
            return $this->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getHttpStatus());
        }
    }
	#[Route('/dev/test-pos-login', name: 'dev_test_pos_login', methods: ['GET'])]
	public function testPosLogin(Request $request): JsonResponse
	{
		try {
			$result = $this->auth->loginPos(
				subdomain:            'koma',
				pin:                  '4499',
				terminalIdentifier:   'test-terminal-001',
				ipAddress:            $request->getClientIp() ?? '',
				userAgent:            $request->headers->get('User-Agent') ?? '',
				deviceName:           'POS Terminal 1',
			);

			return $this->json([
				'status'  => 'success',
				'message' => 'POS login successful.',
				'data'    => $result->toArray(),
			]);

		} catch (AuthException $e) {
			return $this->json([
				'status'  => 'error',
				'message' => $e->getMessage(),
			], $e->getHttpStatus());
		}
	}
}
