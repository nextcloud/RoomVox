<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Sabre\VObject\ITip;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class MailService {
    public function __construct(
        private IMailer $mailer,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private PermissionService $permissionService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send booking accepted email to the organizer
     */
    public function sendAccepted(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking confirmed: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildAcceptedBody($room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Send booking declined email to the organizer
     */
    public function sendDeclined(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking declined: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildDeclinedBody($room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Send conflict notification to the organizer
     */
    public function sendConflict(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking conflict: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildConflictBody($room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Notify managers about a pending booking request
     */
    public function notifyManagers(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $managerUserIds = $this->permissionService->getManagerUserIds($room['id']);
        if (empty($managerUserIds)) {
            $this->logger->warning("RoomVox: No managers found for room {$room['id']}, cannot send approval notification");
            return;
        }

        $subject = "Booking request: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildApprovalRequestBody($room, $eventInfo);

        // Get manager emails via user manager
        $userManager = \OC::$server->get(\OCP\IUserManager::class);
        foreach ($managerUserIds as $managerId) {
            $user = $userManager->get($managerId);
            if ($user === null) {
                continue;
            }

            $email = $user->getEMailAddress();
            if ($email === null || $email === '') {
                continue;
            }

            $this->sendMail($room, $email, $subject, $body);
        }
    }

    /**
     * Send cancellation notification
     */
    public function sendCancelled(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking cancelled: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildCancelledBody($room, $eventInfo);

        // Notify organizer
        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );

        // Also notify managers
        $managerUserIds = $this->permissionService->getManagerUserIds($room['id']);
        $userManager = \OC::$server->get(\OCP\IUserManager::class);
        foreach ($managerUserIds as $managerId) {
            $user = $userManager->get($managerId);
            if ($user === null) {
                continue;
            }
            $email = $user->getEMailAddress();
            if ($email !== null && $email !== '') {
                $this->sendMail($room, $email, $subject, $body);
            }
        }
    }

    /**
     * Send a test email from a room
     */
    public function sendTestEmail(array $room, string $recipientEmail): bool {
        try {
            $this->sendMail(
                $room,
                $recipientEmail,
                "Test email from {$room['name']}",
                "This is a test email from the room booking system.\n\nRoom: {$room['name']}\nEmail: {$room['email']}\n\nIf you receive this, the SMTP configuration is working correctly."
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error("RoomVox: Test email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an email from a room.
     * Uses per-room SMTP config if available, falls back to NC global mailer.
     */
    private function sendMail(
        array $room,
        string $to,
        string $subject,
        string $body,
    ): void {
        if (empty($to)) {
            return;
        }

        $smtpConfig = $room['smtpConfig'] ?? null;
        $fromEmail = $room['email'] ?? '';
        $fromName = $room['name'] ?? 'Room Booking';

        // Use per-room SMTP if configured
        if ($smtpConfig !== null && !empty($smtpConfig['host'])) {
            $this->sendViaRoomSmtp($smtpConfig, $fromEmail, $fromName, $to, $subject, $body);
            return;
        }

        // Fallback: NC global mailer
        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$to]);
            $message->setSubject($subject);
            $message->setPlainBody($body);

            if ($fromEmail !== '') {
                $message->setFrom([$fromEmail => $fromName]);
            }

            $this->mailer->send($message);
            $this->logger->info("RoomVox: Email sent to {$to}: {$subject} (via NC mailer)");
        } catch (\Exception $e) {
            $this->logger->error("RoomVox: Failed to send email to {$to}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send email via per-room SMTP configuration using Symfony Mailer directly.
     */
    private function sendViaRoomSmtp(
        array $smtpConfig,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $body,
    ): void {
        $host = $smtpConfig['host'];
        $port = (int)($smtpConfig['port'] ?? 587);
        $username = $smtpConfig['username'] ?? '';
        $password = $smtpConfig['password'] ?? '';
        $encryption = $smtpConfig['encryption'] ?? 'tls';

        // Decrypt password if encrypted
        if ($password !== '') {
            try {
                $password = $this->crypto->decrypt($password);
            } catch (\Exception $e) {
                // Already decrypted (from getRoom which decrypts) or plain text
            }
        }

        try {
            $tls = ($encryption === 'tls' || $encryption === 'ssl');
            $transport = new EsmtpTransport($host, $port, $tls);

            if ($username !== '') {
                $transport->setUsername($username);
            }
            if ($password !== '') {
                $transport->setPassword($password);
            }

            // Use SMTP username as envelope sender if room email differs
            // (SMTP servers reject sender addresses not owned by the account)
            $senderEmail = $fromEmail;
            if ($username !== '' && $fromEmail !== '' && strtolower($username) !== strtolower($fromEmail)) {
                $senderEmail = $username;
            }

            $email = (new Email())
                ->from("{$fromName} <{$senderEmail}>")
                ->to($to)
                ->subject($subject)
                ->text($body);

            // Set Reply-To as the room email so replies go to the room
            if ($fromEmail !== '' && $senderEmail !== $fromEmail) {
                $email->replyTo($fromEmail);
            }

            $mailer = new SymfonyMailer($transport);
            $mailer->send($email);

            $this->logger->info("RoomVox: Email sent to {$to}: {$subject} (via room SMTP {$host}:{$port})");
        } catch (\Exception $e) {
            $this->logger->error("RoomVox: Failed to send email via room SMTP ({$host}:{$port}) to {$to}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract event info from an iTIP message
     */
    private function extractEventInfo(ITip\Message $message): ?array {
        if ($message->message === null) {
            return null;
        }

        $vEvent = $message->message->VEVENT ?? null;
        if ($vEvent === null) {
            return null;
        }

        $organizer = '';
        $organizerName = '';
        $organizerEmail = '';
        if ($vEvent->ORGANIZER) {
            $organizer = (string)$vEvent->ORGANIZER;
            $organizerEmail = $this->stripMailto($organizer);
            $organizerName = isset($vEvent->ORGANIZER['CN']) ? (string)$vEvent->ORGANIZER['CN'] : $organizerEmail;
        }

        $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
        $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;

        return [
            'uid' => (string)($vEvent->UID ?? ''),
            'summary' => (string)($vEvent->SUMMARY ?? 'Unnamed event'),
            'description' => (string)($vEvent->DESCRIPTION ?? ''),
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'dtstartFormatted' => $dtStart ? $dtStart->format('l, F j, Y H:i') : 'Unknown',
            'dtendFormatted' => $dtEnd ? $dtEnd->format('H:i') : 'Unknown',
            'organizer' => $organizer,
            'organizerEmail' => $organizerEmail,
            'organizerName' => $organizerName,
        ];
    }

    private function buildAcceptedBody(array $room, array $event): string {
        return "Your booking has been confirmed.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n"
            . "Organizer: {$event['organizerName']}\n\n"
            . "The room has been reserved for your event.";
    }

    private function buildDeclinedBody(array $room, array $event): string {
        return "Your booking request has been declined.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "Please contact the room manager for more information.";
    }

    private function buildConflictBody(array $room, array $event): string {
        return "Your booking could not be processed due to a scheduling conflict.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "The room is already booked for this time slot. Please choose a different time.";
    }

    private function buildApprovalRequestBody(array $room, array $event): string {
        return "A new booking request requires your approval.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n"
            . "Requested by: {$event['organizerName']} ({$event['organizerEmail']})\n\n"
            . "Please log in to the Room Booking admin panel to approve or decline this request.";
    }

    private function buildCancelledBody(array $room, array $event): string {
        return "A booking has been cancelled.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n"
            . "Cancelled by: {$event['organizerName']}\n\n"
            . "The room is now available for this time slot.";
    }

    private function stripMailto(string $email): string {
        if (str_starts_with(strtolower($email), 'mailto:')) {
            return substr($email, 7);
        }
        return $email;
    }
}
