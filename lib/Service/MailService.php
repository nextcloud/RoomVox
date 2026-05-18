<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;
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
        private IURLGenerator $urlGenerator,
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
     * Send permission denied email to the organizer
     */
    public function sendPermissionDenied(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking not permitted: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildPermissionDeniedBody($room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Send a decline email when the booking exceeds the room's maximum
     * booking horizon (issue #7). The body names the horizon in days and
     * the earliest date that is no longer bookable so the organizer can
     * reschedule without guessing.
     */
    public function sendHorizonExceeded(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $maxDays = (int)($room['maxBookingHorizon'] ?? 0);
        $subject = $maxDays > 0
            ? "Booking declined: {$room['name']} — {$eventInfo['summary']} (exceeds {$maxDays}-day horizon)"
            : "Booking declined: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildHorizonExceededBody($room, $eventInfo, $maxDays);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Send a decline email when the booking falls outside the room's
     * configured availability hours (issue #7).
     */
    public function sendAvailabilityViolation(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking declined: {$room['name']} — outside availability hours";
        $body = $this->buildAvailabilityViolationBody($room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Send a temporary-failure notice when an Exchange initial sync is
     * still running for the room (issue #7).
     */
    public function sendSyncInProgress(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $subject = "Booking temporarily unavailable: {$room['name']}";
        $body = $this->buildSyncInProgressBody($room, $eventInfo);

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
     * Send booking accepted email from the admin respond flow.
     * Accepts booking data as an array (no iTIP message needed).
     */
    public function sendRespondAccepted(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $subject = "Booking confirmed: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildAcceptedBody($room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body);
    }

    /**
     * Send booking declined email from the admin respond flow.
     * Accepts booking data as an array (no iTIP message needed).
     */
    public function sendRespondDeclined(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $subject = "Booking declined: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildDeclinedBody($room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body);
    }

    /**
     * Send booking cancelled email when an admin/manager deletes an
     * already-accepted booking via the UI (issue #10). Distinct from
     * sendRespondDeclined: that path declines a pending request, this
     * path cancels a booking the user was actively relying on.
     */
    public function sendRespondCancelled(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $subject = "Booking cancelled: {$room['name']} — {$eventInfo['summary']}";
        $body = $this->buildRespondCancelledBody($room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body);
    }

    /**
     * Convert booking metadata (from CalDAVService::updateBookingPartstat)
     * to the event info format used by body builders.
     */
    private function bookingDataToEventInfo(array $data): array {
        $dtStart = !empty($data['dtstart']) ? new \DateTimeImmutable($data['dtstart']) : null;
        $dtEnd = !empty($data['dtend']) ? new \DateTimeImmutable($data['dtend']) : null;

        return [
            'uid' => $data['uid'] ?? '',
            'summary' => $data['summary'] ?: 'Unnamed event',
            'organizerEmail' => $data['organizerEmail'] ?? '',
            'organizerName' => $data['organizerName'] ?: ($data['organizerEmail'] ?? ''),
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'dtstartFormatted' => $dtStart ? $dtStart->format('l, F j, Y H:i') : 'Unknown',
            'dtendFormatted' => $dtEnd ? $dtEnd->format('H:i') : 'Unknown',
        ];
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

            // Only use room email as From if it's a real external address.
            // Internal CalDAV addresses (@roomvox.local) would be rejected by SMTP servers.
            if ($fromEmail !== '' && !str_ends_with(strtolower($fromEmail), '@roomvox.local')) {
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
            $organizerEmail = RoomService::stripMailto($organizer);
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

    private function buildPermissionDeniedBody(array $room, array $event): string {
        return "Your booking request was automatically declined because you do not have permission to book this room.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "Please contact your administrator if you believe this is an error.";
    }

    private function buildHorizonExceededBody(array $room, array $event, int $maxDays): string {
        $explanation = "This room has restrictions on how far in advance it can be booked.";
        if ($maxDays > 0) {
            $cutoff = (new \DateTimeImmutable('+' . $maxDays . ' days'))->format('Y-m-d');
            $explanation = "This room has a maximum booking horizon of {$maxDays} days.\n"
                . "Bookings on or after {$cutoff} are not allowed.";
        }

        return "Your booking request could not be processed because it exceeds the room's booking horizon.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . $explanation . "\n\n"
            . "Please choose a different date or contact the room manager.";
    }

    private function buildAvailabilityViolationBody(array $room, array $event): string {
        $rulesSummary = $this->formatAvailabilityRules($room);
        $rulesBlock = $rulesSummary !== ''
            ? "This room is available during:\n{$rulesSummary}\n\n"
            : '';

        return "Your booking request could not be processed because it falls outside the room's availability hours.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . $rulesBlock
            . "Please choose a time within the room's availability or contact the room manager.";
    }

    private function buildSyncInProgressBody(array $room, array $event): string {
        return "Your booking request could not be processed right now because the room is still being synchronized.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "This is a temporary state — please try again in a few minutes. If the problem persists, contact your administrator.";
    }

    /**
     * Format the room's availability rules as a human-readable summary,
     * e.g. "Mon–Fri 09:00–17:00\nSat 10:00–14:00".
     */
    private function formatAvailabilityRules(array $room): string {
        $rules = $room['availabilityRules']['rules'] ?? [];
        if (empty($rules)) {
            return '';
        }
        $dayLabels = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        $lines = [];
        foreach ($rules as $rule) {
            $days = array_map(fn($d) => $dayLabels[strtolower((string)$d)] ?? (string)$d, $rule['days'] ?? []);
            $daysStr = empty($days) ? '' : implode(', ', $days);
            $start = $rule['startTime'] ?? '';
            $end = $rule['endTime'] ?? '';
            if ($daysStr !== '' && $start !== '' && $end !== '') {
                $lines[] = "  {$daysStr} {$start}–{$end}";
            }
        }
        return implode("\n", $lines);
    }

    private function buildConflictBody(array $room, array $event): string {
        return "Your booking could not be processed due to a scheduling conflict.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "The room is already booked for this time slot. Please choose a different time.";
    }

    private function buildApprovalRequestBody(array $room, array $event): string {
        $settingsUrl = $this->urlGenerator->getAbsoluteURL('/settings/user/' . Application::APP_ID);

        return "A new booking request requires your approval.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n"
            . "Requested by: {$event['organizerName']} ({$event['organizerEmail']})\n\n"
            . "Review and respond:\n{$settingsUrl}\n\n"
            . "Log in to approve or decline this booking request.";
    }

    private function buildCancelledBody(array $room, array $event): string {
        return "A booking has been cancelled.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n"
            . "Cancelled by: {$event['organizerName']}\n\n"
            . "The room is now available for this time slot.";
    }

    private function buildRespondCancelledBody(array $room, array $event): string {
        return "Your booking has been cancelled by a room manager.\n\n"
            . "Room: {$room['name']}\n"
            . "Event: {$event['summary']}\n"
            . "Date: {$event['dtstartFormatted']} – {$event['dtendFormatted']}\n\n"
            . "The room has been released and is no longer reserved for your event.\n"
            . "If you still need this room, please make a new booking or contact the room manager.";
    }

}
