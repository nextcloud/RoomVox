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
    /** Dates named in a decline mail for a series; the rest are counted */
    private const MAX_LISTED_DATES = 10;

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
        private ?InstanceTimezone $instanceTimezone = null,
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
            // Dates in the mail follow its language: without an explicit
            // locale, Nextcloud would take the locale of whoever is logged in
            // (the booker, an admin) and print German text with English
            // month names.
            $locale = $lang !== null && $this->l10nFactory->localeExists($lang) ? $lang : null;
            return $this->l10nCache[$key] = $this->l10nFactory->get(Application::APP_ID, $lang, $locale);
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
                if (!$data instanceof \DateTimeInterface) {
                    return (string)$data;
                }
                return $data->format(match ($type) {
                    'date' => 'l, F j, Y',
                    'time' => 'H:i',
                    default => 'l, F j, Y H:i',
                });
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
        // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title
        $subject = $l->t('Booking confirmed: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildAcceptedBody($l, $room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
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
        // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title
        $subject = $l->t('Booking declined: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildDeclinedBody($l, $room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
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
        // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title
        $subject = $l->t('Booking not permitted: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildPermissionDeniedBody($l, $room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
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
            ? $l->n(
                // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title, %n the number of days ahead the room can be booked
                'Booking declined: %1$s — %2$s (exceeds the %n-day booking horizon)',
                'Booking declined: %1$s — %2$s (exceeds the %n-day booking horizon)',
                $maxDays,
                [$room['name'], $this->summary($l, $eventInfo)],
            )
            : $l->t('Booking declined: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildHorizonExceededBody($l, $room, $eventInfo, $maxDays);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
        );
    }

    /**
     * Send a decline email when the booking falls outside the room's
     * configured availability hours (issue #7).
     */
    /**
     * @param list<\DateTimeInterface> $dates For a series: the occurrences outside the hours
     */
    public function sendAvailabilityViolation(array $room, ITip\Message $message, array $dates = []): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        // TRANSLATORS Mail subject; %s is the room name
        $subject = $l->t('Booking declined: %s — outside availability hours', [$room['name']]);
        $body = $this->buildAvailabilityViolationBody($l, $room, $eventInfo, $dates);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
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
        // TRANSLATORS Mail subject; %s is the room name
        $subject = $l->t('Booking temporarily unavailable: %s', [$room['name']]);
        $body = $this->buildSyncInProgressBody($l, $room, $eventInfo);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
        );
    }

    /**
     * Send conflict notification to the organizer
     */
    /**
     * @param list<\DateTimeInterface> $dates For a series: the occurrences that conflict
     */
    public function sendConflict(array $room, ITip\Message $message, array $dates = []): void {
        $eventInfo = $this->extractEventInfo($message);
        if ($eventInfo === null) {
            return;
        }

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title
        $subject = $l->t('Booking conflict: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildConflictBody($l, $room, $eventInfo, $dates);

        $this->sendMail(
            $room,
            $eventInfo['organizerEmail'],
            $subject,
            $body,
            $l,
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
            // TRANSLATORS Mail subject to room managers; %1$s is the room name, %2$s the booking title
            $subject = $l->t('Booking request: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
            $body = $this->buildApprovalRequestBody($l, $room, $eventInfo);

            $this->sendMail($room, $email, $subject, $body, $l);
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
            // TRANSLATORS Mail subject; %1$s is the room name, %2$s the booking title
            $organizerL10n->t('Booking canceled: %1$s — %2$s', [$room['name'], $this->summary($organizerL10n, $eventInfo)]),
            $this->buildCancelledBody($organizerL10n, $room, $eventInfo),
            $organizerL10n,
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
                    $l->t('Booking canceled: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]),
                    $this->buildCancelledBody($l, $room, $eventInfo),
                    $l,
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
        $subject = $l->t('Booking confirmed: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildAcceptedBody($l, $room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body, $l);
    }

    /**
     * Send booking declined email from the admin respond flow.
     * Accepts booking data as an array (no iTIP message needed).
     */
    public function sendRespondDeclined(array $room, array $bookingData): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $subject = $l->t('Booking declined: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildDeclinedBody($l, $room, $eventInfo);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body, $l);
    }

    /**
     * Send booking cancelled email when an admin/manager deletes an
     * already-accepted booking via the UI (issue #10). Distinct from
     * sendRespondDeclined: that path declines a pending request, this
     * path cancels a booking the user was actively relying on.
     */
    public function sendRespondCancelled(array $room, array $bookingData, ?string $recurrenceId = null): void {
        $eventInfo = $this->bookingDataToEventInfo($bookingData);

        $l = $this->getL10nForEmail($eventInfo['organizerEmail']);
        $occurrenceFormatted = $recurrenceId !== null
            ? $this->formatOccurrence($l, $recurrenceId, $bookingData)
            : null;

        $subject = $occurrenceFormatted !== null
            // TRANSLATORS Mail subject when one date of a recurring booking is canceled; %1$s is the room name, %2$s the booking title
            ? $l->t('Booking canceled: %1$s — %2$s (single occurrence)', [$room['name'], $this->summary($l, $eventInfo)])
            : $l->t('Booking canceled: %1$s — %2$s', [$room['name'], $this->summary($l, $eventInfo)]);
        $body = $this->buildRespondCancelledBody($l, $room, $eventInfo, $occurrenceFormatted);

        $this->sendMail($room, $eventInfo['organizerEmail'], $subject, $body, $l);
    }

    /**
     * Render one occurrence of a series by its recurrence id.
     *
     * The id cannot say what kind of value it is: getBookings() serialises it
     * with format('c'), so an all-day or floating occurrence arrives with a
     * `+00:00` like any instant. RFC 5545 requires RECURRENCE-ID to have the
     * value type of the series' DTSTART, so the series' own flags decide.
     */
    private function formatOccurrence(IL10N $l, string $recurrenceId, array $bookingData): string {
        try {
            [$formatted] = $this->formatEventTimes(
                $l,
                new \DateTimeImmutable($recurrenceId),
                null,
                !empty($bookingData['wallClock']),
                !empty($bookingData['allDay']),
            );
            return $formatted;
        } catch (\Throwable $e) {
            return $recurrenceId;
        }
    }

    /**
     * Convert booking metadata (from CalDAVService::updateBookingPartstat)
     * to the event info format used by body builders.
     */
    private function bookingDataToEventInfo(array $data): array {
        return [
            'uid' => $data['uid'] ?? '',
            'summary' => (string)($data['summary'] ?? ''),
            'organizerEmail' => $data['organizerEmail'] ?? '',
            'organizerName' => $data['organizerName'] ?: ($data['organizerEmail'] ?? ''),
            'dtstart' => !empty($data['dtstart']) ? new \DateTimeImmutable($data['dtstart']) : null,
            'dtend' => !empty($data['dtend']) ? new \DateTimeImmutable($data['dtend']) : null,
            'wallClock' => !empty($data['wallClock']),
            'allDay' => !empty($data['allDay']),
        ];
    }

    /**
     * Render an event's start and end for a mail.
     *
     * Timed events are instants and are shown in the instance timezone, with
     * its name, because the stored zone says nothing about the reader: an
     * event stored in UTC used to be mailed as UTC, two hours off for a
     * booking in Vienna (issue #49). Wall-clock values (all-day, floating;
     * see CalDAVService::isWallClock()) are printed as stored.
     *
     * All-day events have no time worth showing, so they are rendered as
     * dates. DTEND of an all-day event is exclusive (the day after), so the
     * last day shown is the one before it. A single day has no end: the
     * caller prints the start alone.
     *
     * Day and month names and the date order come from the recipient's
     * language ($l->l()), so a German mail does not say "Thursday, October 1".
     *
     * @return array{0: string, 1: ?string} start, and end (null for a single all-day date)
     */
    private function formatEventTimes(IL10N $l, ?\DateTimeInterface $start, ?\DateTimeInterface $end, bool $wallClock, bool $allDay = false): array {
        // IL10N::l() only formats a mutable \DateTime; it keeps its zone.
        $format = static fn (string $type, \DateTimeInterface $moment, string $width): string
            => (string)$l->l($type, \DateTime::createFromInterface($moment), ['width' => $width]);

        if ($allDay && $start !== null) {
            $first = \DateTimeImmutable::createFromInterface($start);
            $last = $end !== null ? \DateTimeImmutable::createFromInterface($end)->modify('-1 day') : $first;

            return [
                $format('date', $first, 'full'),
                $last->format('Y-m-d') > $first->format('Y-m-d') ? $format('date', $last, 'full') : null,
            ];
        }

        $zone = null;
        if (!$wallClock) {
            $zone = $this->instanceTimezone?->get() ?? new \DateTimeZone('UTC');
        }

        $render = function (?\DateTimeInterface $moment, string $type, string $width) use ($zone, $format, $l): string {
            if ($moment === null) {
                // TRANSLATORS Shown in a booking mail in place of a missing start or end time
                return $l->t('Unknown time');
            }
            if ($zone !== null) {
                $moment = \DateTimeImmutable::createFromInterface($moment)->setTimezone($zone);
            }
            return $format($type, $moment, $width);
        };

        $startFormatted = $render($start, 'datetime', 'full|short');
        $endFormatted = $render($end, 'time', 'short');

        if ($zone !== null) {
            $suffix = ' (' . $zone->getName() . ')';
            if ($end !== null) {
                $endFormatted .= $suffix;
            } elseif ($start !== null) {
                $startFormatted .= $suffix;
            }
        }

        return [$startFormatted, $endFormatted];
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
                // TRANSLATORS Mail subject of the SMTP test mail; %s is the room name
                $l->t('Test email from %s', [$room['name']]),
                $l->t('This is a test email from the room booking system.') . "\n\n"
                    // TRANSLATORS Line in a booking mail; %s is the room name
                    . $l->t('Room: %s', [$room['name']]) . "\n"
                    // TRANSLATORS Line in the test mail; %s is the room's email address
                    . $l->t('Email: %s', [$room['email']]) . "\n\n"
                    // TRANSLATORS SMTP is the protocol for sending mail; keep the abbreviation
                    . $l->t('If you receive this, the SMTP configuration is working correctly.'),
                $l,
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
        IL10N $l,
    ): void {
        if (empty($to)) {
            return;
        }

        $smtpConfig = $room['smtpConfig'] ?? null;
        $fromEmail = $room['email'] ?? '';
        // TRANSLATORS Name of the booking function, used as mail sender name and as the title of the personal settings section
        $fromName = $room['name'] ?? $l->t('Room booking');

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
        $wallClock = CalDAVService::isWallClock($vEvent->DTSTART);
        $allDay = CalDAVService::isDateOnly($vEvent->DTSTART);

        return [
            'uid' => (string)($vEvent->UID ?? ''),
            'summary' => (string)($vEvent->SUMMARY ?? ''),
            'description' => (string)($vEvent->DESCRIPTION ?? ''),
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'wallClock' => $wallClock,
            'allDay' => $allDay,
            'organizer' => $organizer,
            'organizerEmail' => $organizerEmail,
            'organizerName' => $organizerName,
        ];
    }

    /**
     * An event's start and end as one recipient reads them.
     *
     * @return array{0: string, 1: ?string}
     */
    private function formatEventInfoTimes(IL10N $l, array $event): array {
        return $this->formatEventTimes(
            $l,
            $event['dtstart'] ?? null,
            $event['dtend'] ?? null,
            !empty($event['wallClock']),
            !empty($event['allDay']),
        );
    }

    /**
     * The event's title, or "Unnamed event" in the recipient's language.
     */
    private function summary(IL10N $l, array $event): string {
        $summary = (string)($event['summary'] ?? '');
        return $summary !== '' ? $summary : $l->t('Unnamed event');
    }

    /**
     * The "Room / Event / Date" block every mail opens with. Each label is a
     * separate translatable string; the values are never translated.
     */
    private function buildEventBlock(IL10N $l, array $room, array $event): string {
        [$start, $end] = $this->formatEventInfoTimes($l, $event);
        $date = $end === null
            // TRANSLATORS Line in a booking mail; %s is a formatted date, or date and time
            ? $l->t('Date: %s', [$start])
            // TRANSLATORS Line in a booking mail; %1$s is the start (date and time), %2$s the end time or end date
            : $l->t('Date: %1$s – %2$s', [$start, $end]);

        return $l->t('Room: %s', [$room['name']]) . "\n"
            // TRANSLATORS Line in a booking mail; %s is the booking title
            . $l->t('Event: %s', [$this->summary($l, $event)]) . "\n"
            . $date . "\n";
    }

    private function buildAcceptedBody(IL10N $l, array $room, array $event): string {
        return $l->t('Your booking has been confirmed.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            // TRANSLATORS Line in a booking mail; %s is the name of the person who booked
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
            // The date in the reader's language and order, not ISO
            $cutoff = (string)$l->l('date', new \DateTime('+' . $maxDays . ' days'), ['width' => 'long']);
            $explanation = $l->n(
                // TRANSLATORS Mail text; %n is the number of days ahead the room can be booked
                'This room has a maximum booking horizon of %n day.',
                'This room has a maximum booking horizon of %n days.',
                $maxDays
            );
            // TRANSLATORS In a mail; %s is a formatted date
            $explanation .= "\n" . $l->t('Bookings on or after %s are not allowed.', [$cutoff]);
        }

        // TRANSLATORS Mail text. The booking horizon is how many days ahead a room can be booked
        return $l->t('Your booking request could not be processed because it exceeds the room\'s booking horizon.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $explanation . "\n\n"
            . $l->t('Please choose a different date or contact the room manager.');
    }

    private function buildAvailabilityViolationBody(IL10N $l, array $room, array $event, array $dates = []): string {
        $rulesSummary = $this->formatAvailabilityRules($l, $room);
        $rulesBlock = $rulesSummary !== ''
            ? $l->t('This room is available during:') . "\n{$rulesSummary}\n\n"
            : '';

        return $l->t('Your booking request could not be processed because it falls outside the room\'s availability hours.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $this->buildDateList($l, $l->t('Dates outside the availability hours:'), $dates, $event)
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
        // Rules store days as 0 (Sunday) to 6 (Saturday), as the room editor
        // and SchedulingPlugin do; the 'mon'…'sun' keys only matched a format
        // nothing writes, so mails printed "1, 2, 3". Both are accepted.
        $names = [
            // TRANSLATORS Abbreviation of Sunday; keep it short
            0 => $l->t('Sun'),
            // TRANSLATORS Abbreviation of Monday; keep it short
            1 => $l->t('Mon'),
            // TRANSLATORS Abbreviation of Tuesday; keep it short
            2 => $l->t('Tue'),
            // TRANSLATORS Abbreviation of Wednesday; keep it short
            3 => $l->t('Wed'),
            // TRANSLATORS Abbreviation of Thursday; keep it short
            4 => $l->t('Thu'),
            // TRANSLATORS Abbreviation of Friday; keep it short
            5 => $l->t('Fri'),
            // TRANSLATORS Abbreviation of Saturday; keep it short
            6 => $l->t('Sat'),
        ];
        $dayLabels = $names + array_combine(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], $names);
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

    private function buildConflictBody(IL10N $l, array $room, array $event, array $dates = []): string {
        if ($dates === []) {
            return $l->t('Your booking could not be processed due to a scheduling conflict.') . "\n\n"
                . $this->buildEventBlock($l, $room, $event) . "\n"
                . $l->t('The room is already booked for this time slot. Please choose a different time.');
        }

        return $l->t('Your booking could not be processed due to a scheduling conflict.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            // TRANSLATORS Heading in a mail above a list of dates on which the room is already booked
            . $this->buildDateList($l, $l->t('Conflicting dates:'), $dates, $event)
            . $l->t('The room is already booked on these dates, so the whole series was declined. Exclude these dates from the series, or choose a different time, and book again.');
    }

    /**
     * A titled list of occurrence dates for a recurring booking, at most
     * MAX_LISTED_DATES of them. Empty for a single booking, whose date is
     * in the event block already.
     *
     * The dates are rendered like the event itself: an all-day series as
     * dates, a floating one as stored, a timed one in the instance timezone.
     *
     * @param list<\DateTimeInterface> $dates
     */
    private function buildDateList(IL10N $l, string $title, array $dates, array $event): string {
        if ($dates === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($dates, 0, self::MAX_LISTED_DATES) as $date) {
            [$formatted] = $this->formatEventTimes($l, $date, null, !empty($event['wallClock']), !empty($event['allDay']));
            $lines[] = '- ' . $formatted;
        }
        $more = count($dates) - self::MAX_LISTED_DATES;
        if ($more > 0) {
            // TRANSLATORS Last line of a list of dates in a mail; %n is the number of dates not listed
            $lines[] = $l->n('and %n more date', 'and %n more dates', $more);
        }

        return $title . "\n" . implode("\n", $lines) . "\n\n";
    }

    private function buildApprovalRequestBody(IL10N $l, array $room, array $event): string {
        $settingsUrl = $this->urlGenerator->getAbsoluteURL('/settings/user/' . Application::APP_ID);

        return $l->t('A new booking request requires your approval.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            // TRANSLATORS Line in a mail to room managers; %1$s is the name, %2$s the email address of the person who booked
            . $l->t('Requested by: %1$s (%2$s)', [$event['organizerName'], $event['organizerEmail']]) . "\n\n"
            // TRANSLATORS In a mail, followed by a link on the next line
            . $l->t('Review and respond:') . "\n{$settingsUrl}\n\n"
            . $l->t('Log in to approve or decline this booking request.');
    }

    private function buildCancelledBody(IL10N $l, array $room, array $event): string {
        return $l->t('A booking has been canceled.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event)
            // TRANSLATORS Line in a mail; %s is the name of the person who canceled
            . $l->t('Canceled by: %s', [$event['organizerName']]) . "\n\n"
            . $l->t('The room is now available for this time slot.');
    }

    private function buildRespondCancelledBody(IL10N $l, array $room, array $event, ?string $occurrenceFormatted = null): string {
        if ($occurrenceFormatted !== null) {
            return $l->t('A single occurrence of your recurring booking has been canceled by a room manager.') . "\n\n"
                . $l->t('Room: %s', [$room['name']]) . "\n"
                . $l->t('Event: %s', [$this->summary($l, $event)]) . "\n"
                // TRANSLATORS Line in a mail; %s is the date of the canceled occurrence of a recurring booking
                . $l->t('Canceled occurrence: %s', [$occurrenceFormatted]) . "\n\n"
                . $l->t('The recurring series continues as scheduled; only this one occurrence has been removed.') . "\n"
                . $l->t('If you still need this room for the canceled time, please make a new booking or contact the room manager.');
        }

        return $l->t('Your booking has been canceled by a room manager.') . "\n\n"
            . $this->buildEventBlock($l, $room, $event) . "\n"
            . $l->t('The room has been released and is no longer reserved for your event.') . "\n"
            . $l->t('If you still need this room, please make a new booking or contact the room manager.');
    }

}
