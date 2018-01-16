<?php

namespace AppBundle\Committee\Feed;

use AppBundle\Committee\CommitteeManager;
use AppBundle\Entity\Adherent;
use AppBundle\Entity\Committee;
use AppBundle\Entity\CommitteeFeedItem;
use AppBundle\Mailer\MailerService;
use AppBundle\Mailer\Message\CommitteeMessageNotificationMessage;
use AppBundle\Repository\CommitteeFeedItemRepository;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CommitteeFeedManager
{
    private $manager;
    private $committeeManager;
    private $mailer;
    private $urlGenerator;
    private $repository;

    public function __construct(ObjectManager $manager, CommitteeManager $committeeManager, MailerService $mailer, UrlGeneratorInterface $urlGenerator, CommitteeFeedItemRepository $repository)
    {
        $this->manager = $manager;
        $this->committeeManager = $committeeManager;
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->repository = $repository;
    }

    public function createEvent(CommitteeEvent $event): CommitteeFeedItem
    {
        $item = CommitteeFeedItem::createEvent(
            $event->getEvent(),
            $event->getAuthor(),
            true,
            $event->getCreatedAt()->format(DATE_RFC2822)
        );

        $this->manager->persist($item);
        $this->manager->flush();

        return $item;
    }

    public function createMessage(CommitteeMessage $message): CommitteeFeedItem
    {
        $item = CommitteeFeedItem::createMessage(
            $message->getCommittee(),
            $message->getAuthor(),
            $message->getContent(),
            $message->isPublished(),
            $message->getCreatedAt()->format(DATE_RFC2822)
        );

        $this->manager->persist($item);
        $this->manager->flush();

        $this->sendMessageToFollowers($item, $message->getCommittee());

        return $item;
    }

    private function sendMessageToFollowers(CommitteeFeedItem $message, Committee $committee): void
    {
        foreach ($this->getOptinCommitteeFollowersChunks($committee) as $chunk) {
            $this->mailer->sendMessage(CommitteeMessageNotificationMessage::create($chunk, $message));
        }
    }

    private function getOptinCommitteeFollowersChunks(Committee $committee)
    {
        return array_chunk(
            $this->committeeManager->getOptinCommitteeFollowers($committee)->toArray(),
            MailerService::PAYLOAD_MAXSIZE
        );
    }

    private function generateUrl(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function removeAuthorItems(Adherent $adherent): void
    {
        $this->repository->removeAuthorItems($adherent);
    }
}
