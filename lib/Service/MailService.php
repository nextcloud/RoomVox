<?php

declare(strict_types=1);

namespace OCA\RoomBooking\Service;

use OCA\RoomBooking\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Sabre\VObject\ITip;

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
        $icalAttachment = $this->buildICalReply($room, $eventInfo, 'ACCEPTED');

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $icalAttachment
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
        $icalAttachment = $this->buildICalReply($room, $eventInfo, 'DECLINED');

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $icalAttachment
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
        $icalAttachment = $this->buildICalReply($room, $eventInfo, 'DECLINED');

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $icalAttachment
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
            $this->logger->warning("RoomBooking: No managers found for room {$room['id']}, cannot send approval notification");
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
        $icalAttachment = $this->buildICalCancel($room, $eventInfo);

        // Notify organizer
        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $icalAttachment
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
            $this->logger->error("RoomBooking: Test email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an email from a room
     */
    private function sendMail(
        array $room,
        string $to,
        string $subject,
        string $body,
        ?string $icalAttachment = null,
    ): void {
        if (empty($to)) {
            return;
        }

        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$to]);
            $message->setSubject($subject);
            $message->setPlainBody($body);

            // Set from address as the room email
            $fromEmail = $room['email'] ?? '';
            $fromName = $room['name'] ?? 'Room Booking';
            if ($fromEmail !== '') {
                $message->setFrom([$fromEmail => $fromName]);
            }

            // Add iCalendar attachment if provided
            if ($icalAttachment !== null) {
                $attachment = $this->mailer->createAttachment(
                    $icalAttachment,
                    'invite.ics',
                    'text/calendar; method=REPLY'
                );
                $message->attach($attachment);
            }

            $this->mailer->send($message);
            $this->logger->debug("RoomBooking: Email sent to {$to}: {$subject}");
        } catch (\Exception $e) {
            $this->logger->error("RoomBooking: Failed to send email to {$to}: " . $e->getMessage());
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

    /**
     * Build iCalendar REPLY attachment
     */
    private function buildICalReply(array $room, array $event, string $partstat): string {
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = $event['dtstart'] ? $event['dtstart']->format('Ymd\THis\Z') : $dtstamp;
        $dtend = $event['dtend'] ? $event['dtend']->format('Ymd\THis\Z') : $dtstamp;

        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Nextcloud RoomBooking//EN\r\n"
            . "METHOD:REPLY\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$event['uid']}\r\n"
            . "DTSTAMP:{$dtstamp}\r\n"
            . "DTSTART:{$dtstart}\r\n"
            . "DTEND:{$dtend}\r\n"
            . "SUMMARY:{$event['summary']}\r\n"
            . "ORGANIZER;CN={$event['organizerName']}:mailto:{$event['organizerEmail']}\r\n"
            . "ATTENDEE;CUTYPE=ROOM;ROLE=NON-PARTICIPANT;PARTSTAT={$partstat};CN={$room['name']}:mailto:{$room['email']}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    /**
     * Build iCalendar CANCEL attachment
     */
    private function buildICalCancel(array $room, array $event): string {
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = $event['dtstart'] ? $event['dtstart']->format('Ymd\THis\Z') : $dtstamp;
        $dtend = $event['dtend'] ? $event['dtend']->format('Ymd\THis\Z') : $dtstamp;

        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Nextcloud RoomBooking//EN\r\n"
            . "METHOD:CANCEL\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$event['uid']}\r\n"
            . "DTSTAMP:{$dtstamp}\r\n"
            . "DTSTART:{$dtstart}\r\n"
            . "DTEND:{$dtend}\r\n"
            . "SUMMARY:{$event['summary']}\r\n"
            . "STATUS:CANCELLED\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    private function stripMailto(string $email): string {
        if (str_starts_with(strtolower($email), 'mailto:')) {
            return substr($email, 7);
        }
        return $email;
    }
}
