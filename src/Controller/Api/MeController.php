<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiResponse;
use App\Dto\Transformer\UserViewFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

final class MeController
{
    public function __construct(private readonly UserViewFactory $userViewFactory)
    {
    }

    /**
     * GET /api/me — identity of the token holder (used by web + mobile on app start).
     */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        // access_control guarantees a fully authenticated user here.
        return ApiResponse::success($this->userViewFactory->create($user));
    }
}
