<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\SendSmsType;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home', methods: ['GET', 'POST'])]
    public function home(Request $request, SmsSenderInterface $sms, SmsConfigResolver $config): Response
    {
        $form   = $this->createForm(SendSmsType::class, ['to' => '+15550100123', 'body' => 'Hello from the SMS Kit demo.']);
        $result = null;
        $error  = null;

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{to: string, body: string} $data */
            $data = $form->getData();
            try {
                $sent   = $sms->send(new SmsMessage($data['to'], $data['body']));
                $result = ['provider' => $sent->providerId, 'to' => E164::mask($data['to']), 'id' => $sent->providerMessageId, 'status' => $sent->status];
            } catch (SmsSendException $e) {
                $error = $e->getMessage();
            }
        }

        $current = $config->current();

        return $this->render('home.html.twig', [
            'form'       => $form->createView(),
            'provider'   => $sms->getProviderId(),
            'configured' => $sms->isConfigured(),
            'sender'     => $current->sender,
            'recipient'  => E164::mask($current->testRecipient),
            'result'     => $result,
            'error'      => $error,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }
}
