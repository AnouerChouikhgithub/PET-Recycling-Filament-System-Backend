<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use App\Entity\User;
use App\Repository\AgentMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/** The model context must never read a soft-deleted conversation. */
final class AgentMessageRepositoryTest extends KernelTestCase
{
    public function testFindContextExcludesSoftDeletedConversations(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = static::getContainer()->get(AgentMessageRepository::class);

        $user = new User();
        $user->setEmail('ctx-'.strtolower(Uuid::v7()->toBase32()).'@3awedlou.test')->setName('Context Test');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'ctx-pass-123'));

        $conversation = new AgentConversation($user);
        $em->persist($user);
        $em->persist($conversation);
        $em->persist(new AgentMessage($conversation, AgentMessage::ROLE_USER, 'hello'));
        $em->flush();

        self::assertCount(1, $repo->findContext($conversation, 12));

        $conversation->softDelete();
        $em->flush();

        self::assertSame([], $repo->findContext($conversation, 12), 'soft-deleted conversation yields no model context');
    }
}
