<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\DeviceToken;
use App\Entity\Machine;
use App\Repository\DeviceTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Device authentication for the IoT surface (telemetry ingest today, the
 * future MQTT consumer / ESP32 later).
 *
 * The device presents the per-machine plaintext token in the dedicated
 * X-Device-Token header; only its SHA-512 hash is stored server-side. This is
 * a DIFFERENT scheme from the user JWT on purpose:
 *
 *   - a regular user JWT can never ingest telemetry (it does not satisfy this
 *     authenticator, and the ingest route requires this firewall);
 *   - tokens are revocable per machine without touching any user account.
 *
 * The authenticated "user" is the Machine itself (a UserInterface adapter),
 * so ownership is by construction: a device token can only ever act on the
 * machine it was issued for.
 */
final class DeviceTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly DeviceTokenRepository $deviceTokens,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has(DeviceToken::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = trim((string) $request->headers->get(DeviceToken::HEADER, ''));

        if ('' === $presented || strlen($presented) > 256) {
            throw new CustomUserMessageAuthenticationException('Invalid device token.');
        }

        $token = $this->deviceTokens->findActiveByHash(hash('sha512', $presented));

        if (null === $token) {
            // Same message for unknown, revoked or malformed tokens.
            throw new CustomUserMessageAuthenticationException('Invalid device token.');
        }

        $machine = $token->getMachine();
        $token->markUsed();

        return new SelfValidatingPassport(new UserBadge($machine->getIdentifier(), fn (): \Symfony\Component\Security\Core\User\UserInterface => new DeviceUser($machine)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // let the controller run
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => 'INVALID_DEVICE_TOKEN',
                'message' => $exception->getMessage(),
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }

    /** Entry point: no/invalid credentials reached the device-only surface. */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'Device authentication required. Send the machine token in the '.DeviceToken::HEADER.' header.',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
