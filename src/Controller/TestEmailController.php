<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class TestEmailController extends AbstractController
{
    #[Route('/test-email', name: 'test_email')]
    public function testEmail(MailerInterface $mailer): Response
    {
        try {
            $email = (new Email())
                ->from('fethizouabi190@gmail.com')
                ->to('sabribenfalah03@gmail.com')
                ->subject('🧪 Test Email Symfony')
                ->text('Si vous voyez ce message, le mailer fonctionne! ✅');

            $mailer->send($email);

            return new Response('✅ Email envoyé avec succès! Vérifiez votre boîte Gmail', 200);
        } catch (\Exception $e) {
            return new Response('❌ Erreur mailer: ' . $e->getMessage() . '\n\nTrace: ' . $e->getTraceAsString(), 500);
        }
    }
}