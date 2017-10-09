<?php

namespace AppBundle\Membership;

use AppBundle\Address\PostAddressFactory;
use AppBundle\CitizenInitiative\ActivitySubscriptionManager;
use AppBundle\CitizenInitiative\CitizenInitiativeManager;
use AppBundle\CitizenProject\CitizenProjectManager;
use AppBundle\Committee\CommitteeManager;
use AppBundle\Committee\Feed\CommitteeFeedManager;
use AppBundle\Entity\Adherent;
use AppBundle\Entity\AdherentActivationToken;
use AppBundle\Entity\Committee;
use AppBundle\Entity\Summary;
use AppBundle\Event\EventManager;
use AppBundle\Event\EventRegistrationManager;
use AppBundle\CitizenAction\CitizenActionManager;
use AppBundle\Mailer\MailerService;
use AppBundle\Mailer\Message\AdherentAccountActivationMessage;
use AppBundle\Mailer\Message\AdherentTerminateMembershipMessage;
use AppBundle\Report\ReportManager;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MembershipRequestHandler
{
    private $dispatcher;
    private $addressFactory;
    private $urlGenerator;
    private $mailer;
    private $manager;
    private $committeeManager;
    private $registrationManager;
    private $citizenInitiativeManager;
    private $citizenActionManager;
    private $eventManager;
    private $committeeFeedManager;
    private $activitySubscriptionManager;
    private $citizenProjectManager;
    private $reportManager;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        PostAddressFactory $addressFactory,
        UrlGeneratorInterface $urlGenerator,
        MailerService $mailer,
        ObjectManager $manager,
        CommitteeManager $committeeManager,
        EventRegistrationManager $registrationManager,
        CitizenInitiativeManager $citizenInitiativeManager,
        CitizenActionManager $citizenActionManager,
        EventManager $eventManager,
        CommitteeFeedManager $committeeFeedManager,
        ActivitySubscriptionManager $activitySubscriptionManager,
        CitizenProjectManager $citizenProjectManager,
        ReportManager $reportManager
    ) {
        $this->addressFactory = $addressFactory;
        $this->dispatcher = $dispatcher;
        $this->urlGenerator = $urlGenerator;
        $this->mailer = $mailer;
        $this->manager = $manager;
        $this->committeeManager = $committeeManager;
        $this->registrationManager = $registrationManager;
        $this->citizenInitiativeManager = $citizenInitiativeManager;
        $this->citizenActionManager = $citizenActionManager;
        $this->committeeFeedManager = $committeeFeedManager;
        $this->eventManager = $eventManager;
        $this->activitySubscriptionManager = $activitySubscriptionManager;
        $this->citizenProjectManager = $citizenProjectManager;
        $this->reportManager = $reportManager;
    }

    public function handle(Adherent $adherent, MembershipRequest $membershipRequest)
    {
        $adherent->updateMembership($membershipRequest, $this->addressFactory->createFromAddress($membershipRequest->getAddress()));
        $adherent->adhere();

        $token = AdherentActivationToken::generate($adherent);

        $this->manager->persist($token);
        $this->manager->flush();

        $activationUrl = $this->generateMembershipActivationUrl($adherent, $token);
        $this->mailer->sendMessage(AdherentAccountActivationMessage::createFromAdherent($adherent, $activationUrl));

        $this->dispatcher->dispatch(AdherentEvents::REGISTRATION_COMPLETED, new AdherentAccountWasCreatedEvent($adherent));
    }

    public function update(Adherent $adherent, MembershipRequest $membershipRequest)
    {
        $adherent->updateMembership($membershipRequest, $this->addressFactory->createFromAddress($membershipRequest->getAddress()));

        $this->dispatcher->dispatch(AdherentEvents::PROFILE_UPDATED, new AdherentProfileWasUpdatedEvent($adherent));

        $this->manager->flush();
    }

    private function generateMembershipActivationUrl(Adherent $adherent, AdherentActivationToken $token)
    {
        $params = [
            'adherent_uuid' => (string) $adherent->getUuid(),
            'activation_token' => (string) $token->getValue(),
        ];

        return $this->urlGenerator->generate('app_membership_activate', $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function terminateMembership(UnregistrationCommand $command, Adherent $adherent, $removeAccount = false)
    {
        $unregistrationFactory = new UnregistrationFactory();
        $unregistration = $unregistrationFactory->createFromUnregistrationCommandAndAdherent($command, $adherent);

        $this->manager->persist($unregistration);

        $adherent->leave();

        if ($removeAccount) {
            $summary = $this->manager->getRepository(Summary::class)->findOneForAdherent($adherent);
            $token = $this->manager->getRepository(AdherentActivationToken::class)->findOneBy(['adherentUuid' => $adherent->getUuid()->toString()]);

            $this->removeAdherentMemberShips($adherent);
            $this->citizenActionManager->removeOrganizerCitizenActions($adherent);
            $this->citizenInitiativeManager->removeOrganizerCitizenInitiatives($adherent);
            $this->eventManager->removeOrganizerEvents($adherent);
            $this->registrationManager->anonymizeAdherentRegistrations($adherent);
            $this->committeeFeedManager->removeAuthorItems($adherent);
            $this->activitySubscriptionManager->removeAdherentActivities($adherent);
            $this->citizenProjectManager->removeAuthorItems($adherent);
            $this->reportManager->anonymAuthorReports($adherent);

            if ($token) {
                $this->manager->remove($token);
            }
            if ($summary) {
                $this->manager->remove($summary);
            }

            $this->manager->remove($adherent);
        }

        $this->manager->flush();

        $this->mailer->sendMessage(AdherentTerminateMembershipMessage::createFromAdherent($adherent));
    }

    private function removeAdherentMemberShips(Adherent $adherent): void
    {
        $committeeRepository = $this->manager->getRepository(Committee::class);

        foreach ($adherent->getMemberships() as $membership) {
            $committee = $committeeRepository->findOneBy(['uuid' => $membership->getCommitteeUuid()->toString()]);
            $this->committeeManager->unfollowCommittee($adherent, $committee, false);
        }
    }
}
