<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Twig\Environment;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private string $fromEmail
    ) {}

    public function sendMaintenanceAlert(
        string $recipientEmail,
        string $recipientName,
        string $equipementName,
        string $maintenanceType,
        string $maintenanceDate,
        string $technicianName
    ): void {
        try {
            // Rendu du template
            $htmlContent = $this->twig->render('emails/maintenance_alert.html.twig', [
                'recipientName' => $recipientName,
                'equipementName' => $equipementName,
                'maintenanceType' => $maintenanceType,
                'maintenanceDate' => $maintenanceDate,
                'technicianName' => $technicianName,
            ]);

            $email = (new Email())
                ->from(new Address($this->fromEmail, 'Système de Gestion Agricole'))
                ->to(new Address($recipientEmail, $recipientName))
                ->subject('🚨 Alerte Maintenance - Équipement Critique')
                ->html($htmlContent);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            // Vous pouvez logger l'erreur ici
            error_log('Erreur lors de l\'envoi du mail: ' . $e->getMessage());
            throw new \Exception('Impossible d\'envoyer le mail: ' . $e->getMessage());
        }
    }

    public function sendGenericEmail(
        string $recipientEmail,
        string $subject,
        string $htmlContent
    ): void {
        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, 'Système de Gestion Agricole'))
                ->to($recipientEmail)
                ->subject($subject)
                ->html($htmlContent);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'envoi du mail: ' . $e->getMessage());
            throw new \Exception('Impossible d\'envoyer le mail: ' . $e->getMessage());
        }
    }
}
