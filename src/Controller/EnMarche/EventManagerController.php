<?php

namespace AppBundle\Controller\EnMarche;

use AppBundle\Controller\PrintControllerTrait;
use AppBundle\Entity\EventRegistration;
use AppBundle\Event\EventCommand;
use AppBundle\Event\EventContactMembersCommand;
use AppBundle\Entity\Event;
use AppBundle\Exception\BadUuidRequestException;
use AppBundle\Exception\InvalidUuidException;
use AppBundle\Form\ContactMembersType;
use AppBundle\Form\EventCommandType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/evenements/{slug}")
 * @Security("is_granted('HOST_EVENT', event)")
 */
class EventManagerController extends Controller
{
    use PrintControllerTrait;

    private const ACTION_CONTACT = 'contact';
    private const ACTION_EXPORT = 'export';
    private const ACTION_PRINT = 'print';

    /**
     * @Route("/modifier", name="app_event_edit")
     * @Method("GET|POST")
     * @Entity("event", expr="repository.findOneActiveBySlug(slug)")
     */
    public function editAction(Request $request, Event $event): Response
    {
        $form = $this->createForm(EventCommandType::class, $command = EventCommand::createFromEvent($event));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.handler')->handleUpdate($event, $command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.update.success'));

            return $this->redirectToRoute('app_event_show', [
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('events/edit.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/annuler", name="app_event_cancel")
     * @Method("GET|POST")
     * @Entity("event", expr="repository.findOneActiveBySlug(slug)")
     */
    public function cancelAction(Request $request, Event $event): Response
    {
        $command = EventCommand::createFromEvent($event);

        $form = $this->createForm(FormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.handler')->handleCancel($event, $command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.cancel.success'));

            return $this->redirectToRoute('app_event_show', [
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('events/cancel.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/inscrits", name="app_event_members")
     * @Method("GET")
     */
    public function membersAction(Event $event): Response
    {
        $registrations = $this->getDoctrine()->getRepository(EventRegistration::class)->findByEvent($event);

        return $this->render('events/members.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'registrations' => $registrations,
        ]);
    }

    /**
     * @Route("/inscrits/exporter", name="app_event_export_members")
     * @Method("POST")
     */
    public function exportMembersAction(Request $request, Event $event): Response
    {
        $registrations = $this->getRegistrations($request, $event, self::ACTION_EXPORT);

        if (!$registrations) {
            return $this->redirectToRoute('app_event_members', [
                'slug' => $event->getSlug(),
            ]);
        }

        $exported = $this->get('app.event.registration_exporter')->export($registrations);

        return new Response($exported, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inscrits-a-l-evenement.csv"',
        ]);
    }

    /**
     * @Route("/inscrits/contacter", name="app_event_contact_members")
     * @Method("POST")
     */
    public function contactMembersAction(Request $request, Event $event): Response
    {
        $registrations = $this->getRegistrations($request, $event, self::ACTION_CONTACT);

        if (!$registrations) {
            return $this->redirectToRoute('app_event_members', [
                'slug' => $event->getSlug(),
            ]);
        }

        $command = new EventContactMembersCommand($registrations, $this->getUser());

        $form = $this->createForm(ContactMembersType::class, $command, ['csrf_token_id' => 'event.contact_members'])
            ->add('submit', SubmitType::class)
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.contact_members_handler')->handle($command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.contact.success'));

            return $this->redirectToRoute('app_event_members', [
                'slug' => $event->getSlug(),
            ]);
        }

        $uuids = array_map(function (EventRegistration $registration) {
            return $registration->getUuid()->toString();
        }, $registrations);

        return $this->render('events/contact_members.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'contacts' => $uuids,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/inscrits/imprimer", name="app_event_print_members")
     * @Method("POST")
     */
    public function printMembersAction(Request $request, Event $event): Response
    {
        $registrations = $this->getRegistrations($request, $event, self::ACTION_PRINT);

        if (!$registrations) {
            return $this->redirectToRoute('app_event_members', [
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->getPdfResponse(
            'events/print_members.html.twig',
            [
                'registrations' => $registrations,
            ],
            'Liste des participants.pdf'
        );
    }

    private function getRegistrations(Request $request, Event $event, string $action): array
    {
        if (!$this->isCsrfTokenValid(sprintf('event.%s_members', $action), $request->request->get('token'))) {
            throw $this->createAccessDeniedException("Invalid CSRF protection token to $action members.");
        }

        if (!$uuids = json_decode($request->request->get(sprintf('%ss', $action)), true)) {
            if (self::ACTION_CONTACT === $action) {
                $this->addFlash('info', $this->get('translator')->trans('committee.event.contact.none'));
            }

            return [];
        }

        $repository = $this->getDoctrine()->getRepository(EventRegistration::class);

        try {
            $registrations = $repository->findByUuidAndEvent($event, $uuids);
        } catch (InvalidUuidException $e) {
            throw new BadUuidRequestException($e);
        }

        return $registrations;
    }
}
