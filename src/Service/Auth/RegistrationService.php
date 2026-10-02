<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Api\Exception\ValidationException;
use App\Dto\Request\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Account registration — shared by web + mobile clients.
 */
final class RegistrationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws ValidationException when the payload is invalid or the email is taken
     */
    public function register(RegisterRequest $request): User
    {
        $violations = $this->validator->validate($request, groups: ['Default']);
        if (count($violations) > 0) {
            throw ValidationException::fromViolations($violations);
        }

        $email = strtolower(trim($request->email));
        if (null !== $this->users->findByEmail($email)) {
            throw new ValidationException('The provided data is invalid.', [
                'email' => ['This email is already registered.'],
            ]);
        }

        $user = new User();
        $user->setEmail($email)
            ->setName($request->name)
            ->setPassword($this->passwordHasher->hashPassword($user, $request->password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
