<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Sabre\VObject\ITip;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class MailService {
    /** @var array<string, IL10N> language code → translator */
    private array $l10nCache = [];

    public function __construct(
        private IMailer $mailer,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private PermissionService $permissionService,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ?IFactory $l10nFactory = null,
    ) {
    }

    /**
     * Translator for a recipient, by email address.
     *
     * Mails must arrive in the *recipient's* language, not the server's: the
     * organizer, the room managers and the admin can each have a different one
     * (issue #24). The address is resolved back to a Nextcloud account to read
     * its configured language; external addresses fall back to the instance
     * default, which is all we can know about them.
     */
    private function getL10nForEmail(string $email): IL10N {
        $lang = null;

        if ($email !== '' && $this->l10nFactory !== null) {
            $users = $this->userManager->getByEmail($email);
            if (count($users) === 1) {
                $lang = $this->l10nFactory->getUserLanguage($users[0]);
            }
        }

        return $this->getL10n($lang);
    }

    /**
     * Translator for an explicit language (null = instance default).
     */
    private function getL10n(?string $lang = null): IL10N {
        $key = $lang ?? '';
        if (isset($this->l10nCache[$key])) {
            return $this->l10nCache[$key];
        }

        if ($this->l10nFactory !== null) {
            return $this->l10nCache[$key] = $this->l10nFactory->get(Application::APP_ID, $lang);
        }

        // No factory injected (unit tests): fall back to a pass-through so the
        // mails stay readable English rather than blowing up.
        return $this->l10nCache[$key] = new class implements IL10N {
            public function t(string $text, $parameters = []): string {
                if (!is_array($parameters)) {
                    $parameters = [$parameters];
                }
                return $parameters === [] ? $text : vsprintf($text, $parameters);
            }

            public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
                $text = $count === 1 ? $text_singular : $text_plural;
                $text = str_replace('%n', (string)$count, $text);
                return $parameters === [] ? $text : vsprintf($text, $parameters);
            }

            public function l(string $type, $data, array $options = []) {
                return (string)$data;
            }

            public function getLanguageCode(): string {
                return 'en';
            }

            public function getLocaleCode(): string {
                return 'en_US';
            }
        };
    }

    /**
     * Send booking accepted email to the organizer
     */
    public function sendAccepted(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking confirmed: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildAcceptedBody($l, $room, $eventInfo);

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

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking declined: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildDeclinedBody($l, $room, $eventInfo);

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

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking not permitted: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildPermissionDeniedBody($l, $room, $eventInfo);

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
        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $maxDays > 0
            ? $l->t('Booking declined: %1$s — %2$s (exceeds %3$d-day horizon)', [$room['name'], $eventInfo['summary'], $maxDays])
            : $l->t('Booking declined: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildHorizonExceededBody($l, $room, $eventInfo, $maxDays);

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

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking declined: %s — outside availability hours', [$room['name']]);
        $body = $this->buildAvailabilityViolationBody($l, $room, $eventInfo);

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

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking temporarily unavailable: %s', [$room['name']]);
        $body = $this->buildSyncInProgressBody($l, $room, $eventInfo);

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

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking conflict: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildConflictBody($l, $room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
        );
    }

    /**
     * Notify managers about a pending booking request triggered via the
     * iTIP/CalDAV path.
     */
    public function notifyManagers(array $room, ITip\Message $message): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }
        $this->notifyManagersFromEventInfo($room, $eventInfo);
    }

    /**
     * Notify managers about a pending booking request created via the REST API
     * (PublicApiController / BookingApiController), which bypasses the Sabre
     * scheduling plugin.
     */
    public function notifyManagersForBooking(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);
        $this->notifyManagersFromEventInfo($room, $eventInfo);
    }

    private function notifyManagersFromEventInfo(array $room, array $eventInfo): void {
        $managerUserIds = $this->permissionService->getManagerUserIds($room['id']);
        if (empty($managerUserIds)) {
            $this->logger->warning("RoomVox: No managers found for room {$room['id']}, cannot send approval notification");
            return;
        }

        // Each manager can have their own language, so subject and body are
        // rendered per recipient rather than once for everyone (issue #24).
        foreach ($managerUserIds as $managerId) {
            $user = $this->userManager->get($managerId);
            if ($user === null) {
                continue;
            }

            $email = $user->getEMailAddress();
            if ($email === null || $email === '') {
                continue;
            }

            $l = $this->getL10n(
                $this->l10nFactory !== null ? $this->l10nFactory->getUserLanguage($user) : null
            );
            $subject = $l->t('Booking request: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
            $body = $this->buildApprovalRequestBody($l, $room, $eventInfo);

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

        // Notify organizer, in their own language
        $organizerL10n = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $organizerL10n->t('Booking cancelled: %1$s — %2$s', [$room['name'], $eventInfo['summary']]),
            $this->buildCancelledBody($organizerL10n, $room, $eventInfo),
        );

        // Also notify managers — each in theirs
        $managerUserIds = $this->permissionService->getManagerUserIds($room['id']);
        foreach ($managerUserIds as $managerId) {
            $user = $this->userManager->get($managerId);
            if ($user === null) {
                continue;
            }
            $email = $user->getEMailAddress();
            if ($email !== null && $email !== '') {
                $l = $this->getL10n(
                    $this->l10nFactory !== null ? $this->l10nFactory->getUserLanguage($user) : null
                );
                $this->sendMail(
                    $room,
                    $email,
                    $l->t('Booking cancelled: %1$s — %2$s', [$room['name'], $eventInfo['summary']]),
                    $this->buildCancelledBody($l, $room, $eventInfo),
                );
            }
        }
    }

    /**
     * Send booking accepted email from the admin respond flow.
     * Accepts booking data as an array (no iTIP message needed).
     */
    public function sendRespondAccepted(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking confirmed: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildAcceptedBody($l, $room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body);
    }

    /**
     * Send booking declined email from the admin respond flow.
     * Accepts booking data as an array (no iTIP message needed).
     */
    public function sendRespondDeclined(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking declined: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildDeclinedBody($l, $room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body);
    }

    /**
     * Send booking cancelled email when an admin/manager deletes an
     * already-accepted booking via the UI (issue #10). Distinct from
     * sendRespondDeclined: that path declines a pending request, this
     * path cancels a booking the user was actively relying on.
     */
    public function sendRespondCancelled(array $room, array $bookingData, ?string $recurrenceId = null): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $occurrenceFormatted = null;
        if ($recurrenceId !== null) {
            try {
                $occurrenceFormatted = (new \DateTimeImmutable($recurrenceId))->format('l, F j, Y H:i');
            } catch (\Throwable $e) {
                $occurrenceFormatted = $recurrenceId;
            }
        }

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $occurrenceFormatted !== null
            ? $l->t('Booking cancelled: %1$s — %2$s (single occurrence)', [$room['name'], $eventInfo['summary']])
            : $l->t('Booking cancelled: %1$s — %2$s', [$room['name'], $eventInfo['summary']]);
        $body = $this->buildRespondCancelledBody($l, $room, $eventInfo, $occurrenceFormatted);

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
            $l = $this->getL10nForEmail($recipientEmail);
            $this->sendMail(
                $room,
                $recipientEmail,
                $l->t('Test email from %s', [$room['name']]),
                $l->t('This is a test email from the room booking system.') . "\n\n"
                    . $l->t('Room: %s', [$room['name']]) . "\n"
                    . $l->t('Email: %s', [$room['email']]) . "\n\n"
                    . $l->t('If you receive this, the SMTP configuration is working correctly.')
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

    /**
     * The "Room / Event / Date" block every mail opens with. Each label is a
     * separate translatable string; the values are never translated.
     */
    private function buildEventBlock(IL10N $l, array $room, array $event): string {
        return $l->t('Room: %s', [$room['name']]) . "\n"
            . $l->t('Event: %s', [$event['summary']]) . "\n"
            . $l->t('Date: %1$s – %2$s', [$event['dtstartFormatted'], $event['dtendFormatted']]) . "\n";
    }

    private function buildAcceptedBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking has been confirmed.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            . $l->t('Organizer: %s', [$event['organizerName']]) . "\n\n"
            . $l->t('The room has been reserved for your event.');
    }

    private function buildDeclinedBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking request has been declined.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('Please contact the room manager for more information.');
    }

    private function buildPermissionDeniedBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking request was automatically declined because you do not have permission to book this room.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('Please contact your administrator if you believe this is an error.');
    }

    private function buildHorizonExceededBody(IL10N $l, array $room, array $event, int $maxDays): string {
        $explanation = $l->t('This room has restrictions on how far in advance it can be booked.');
        if ($maxDays > 0) {
            $cutoff = (new \DateTimeImmutable('+' . $maxDays . ' days'))->format('Y-m-d');
            $explanation = $l->n(
                'This room has a maximum booking horizon of %n day.',
                'This room has a maximum booking horizon of %n days.',
                $maxDays
            ) . "\n" . $l->t('Bookings on or after %s are not allowed.', [$cutoff]);
        }

        return $l->t('Your booking request could not be processed because it exceeds the room\'s booking horizon.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $explanation . "\n\n"
            . $l->t('Please choose a different date or contact the room manager.');
    }

    private function buildAvailabilityViolationBody(IL10N $l, array $room, array $event): string {
        $rulesSummary = $this->formatAvailabilityRules($l, $room);
        $rulesBlock = $rulesSummary !== ''
            ? $l->t('This room is available during:') . "\n{$rulesSummary}\n\n"
            : '';

        return $l->t('Your booking request could not be processed because it falls outside the room\'s availability hours.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $rulesBlock
            . $l->t('Please choose a time within the room\'s availability or contact the room manager.');
    }

    private function buildSyncInProgressBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking request could not be processed right now because the room is still being synchronized.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('This is a temporary state — please try again in a few minutes. If the problem persists, contact your administrator.');
    }

    /**
     * Format the room's availability rules as a human-readable summary,
     * e.g. "Mon–Fri 09:00–17:00\nSat 10:00–14:00".
     */
    private function formatAvailabilityRules(IL10N $l, array $room): string {
        $rules = $room['availabilityRules']['rules'] ?? [];
        if (empty($rules)) {
            return '';
        }
        $dayLabels = [
            'mon' => $l->t('Mon'),
            'tue' => $l->t('Tue'),
            'wed' => $l->t('Wed'),
            'thu' => $l->t('Thu'),
            'fri' => $l->t('Fri'),
            'sat' => $l->t('Sat'),
            'sun' => $l->t('Sun'),
        ];
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

    private function buildConflictBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking could not be processed due to a scheduling conflict.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('The room is already booked for this time slot. Please choose a different time.');
    }

    private function buildApprovalRequestBody(IL10N $l, array $room, array $event): string {
        $settingsUrl = $this->urlGenerator->getAbsoluteURL('/settings/user/' . Application::APP_ID);

        return $l->t('A new booking request requires your approval.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            . $l->t('Requested by: %1$s (%2$s)', [$event['organizerName'], $event['organizerEmail']]) . "\n\n"
            . $l->t('Review and respond:') . "\n{$settingsUrl}\n\n"
            . $l->t('Log in to approve or decline this booking request.');
    }

    private function buildCancelledBody(IL10N $l, array $room, array $event): string {
        return $l->t('A booking has been cancelled.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            . $l->t('Cancelled by: %s', [$event['organizerName']]) . "\n\n"
            . $l->t('The room is now available for this time slot.');
    }

    private function buildRespondCancelledBody(IL10N $l, array $room, array $event, ?string $occurrenceFormatted = null): string {
        if ($occurrenceFormatted !== null) {
            return $l->t('A single occurrence of your recurring booking has been cancelled by a room manager.') . "\n\n"
                . $l->t('Room: %s', [$room['name']]) . "\n"
                . $l->t('Event: %s', [$event['summary']]) . "\n"
                . $l->t('Cancelled occurrence: %s', [$occurrenceFormatted]) . "\n\n"
                . $l->t('The recurring series continues as scheduled; only this one occurrence has been removed.') . "\n"
                . $l->t('If you still need this room for the cancelled time, please make a new booking or contact the room manager.');
        }

        return $l->t('Your booking has been cancelled by a room manager.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('The room has been released and is no longer reserved for your event.') . "\n"
            . $l->t('If you still need this room, please make a new booking or contact the room manager.');
    }

}
