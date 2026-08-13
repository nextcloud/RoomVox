<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #24 — notification mails were always English.
 *
 * MailService had no IL10N at all: every subject and body was a hardcoded
 * English string, so a French user with a French UI still received English
 * mail. Crucially the language must be resolved per *recipient* — organizer
 * and managers can each have a different one — not from the server default.
 */
class MailServiceL10nTest extends TestCase {
    private IUserManager $userManager;
    private IFactory $l10nFactory;

    /** @var array<string, string> email → language */
    private array $languageByEmail = [];

    /** @var list<string> languages actually requested from the factory */
    private array $requestedLanguages = [];

    private function makeService(): MailService {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('getByEmail')->willReturnCallback(
            function (string $email) {
                if (!isset($this->languageByEmail[$email])) {
                    return [];
                }
                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn($email);
                return [$user];
            }
        );

        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->l10nFactory->method('getUserLanguage')->willReturnCallback(
            fn (?IUser $user) => $this->languageByEmail[$user?->getUID() ?? ''] ?? 'en'
        );
        $this->l10nFactory->method('get')->willReturnCallback(
            function (string $app, ?string $lang = null) {
                $this->requestedLanguages[] = $lang ?? '(default)';
                $l = $this->createMock(IL10N::class);
                // Prefix every string with the language so assertions can see
                // which translator produced the text.
                $l->method('t')->willReturnCallback(
                    fn (string $text, $params = []) => '[' . ($lang ?? 'default') . '] '
                        . (is_array($params) && $params !== [] ? vsprintf($text, $params) : $text)
                );
                $l->method('n')->willReturnCallback(
                    fn (string $s, string $p, int $c, array $params = []) => '[' . ($lang ?? 'default') . '] '
                        . str_replace('%n', (string)$c, $c === 1 ? $s : $p)
                );
                return $l;
            }
        );

        return new MailService(
            $this->createMock(IMailer::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(PermissionService::class),
            $this->userManager,
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
            $this->l10nFactory,
        );
    }

    private function callGetL10nForEmail(MailService $service, string $email): IL10N {
        $method = new \ReflectionMethod($service, 'getL10nForEmail');
        return $method->invoke($service, $email);
    }

    public function testRecipientLanguageIsUsedNotTheServerDefault(): void {
        $this->languageByEmail = ['pierre@example.com' => 'fr'];
        $service = $this->makeService();

        $l = $this->callGetL10nForEmail($service, 'pierre@example.com');

        $this->assertStringContainsString('[fr]', $l->t('Your booking has been confirmed.'));
        $this->assertContains('fr', $this->requestedLanguages);
    }

    /** Two recipients with different languages must get different translators. */
    public function testEachRecipientGetsTheirOwnLanguage(): void {
        $this->languageByEmail = [
            'pierre@example.com' => 'fr',
            'dieter@example.com' => 'de',
        ];
        $service = $this->makeService();

        $fr = $this->callGetL10nForEmail($service, 'pierre@example.com');
        $de = $this->callGetL10nForEmail($service, 'dieter@example.com');

        $this->assertStringContainsString('[fr]', $fr->t('Booking confirmed'));
        $this->assertStringContainsString('[de]', $de->t('Booking confirmed'));
    }

    /** An address that is not a Nextcloud user falls back to the default. */
    public function testExternalAddressFallsBackToInstanceDefault(): void {
        $this->languageByEmail = [];
        $service = $this->makeService();

        $l = $this->callGetL10nForEmail($service, 'someone@external.example');

        $this->assertStringContainsString('[default]', $l->t('Your booking has been confirmed.'));
    }

    /** An ambiguous address (two accounts) must not guess a language. */
    public function testAmbiguousAddressFallsBackToInstanceDefault(): void {
        $this->makeService(); // wires up $this->l10nFactory

        $ambiguous = $this->createMock(IUserManager::class);
        $ambiguous->method('getByEmail')->willReturn([
            $this->createMock(IUser::class),
            $this->createMock(IUser::class),
        ]);

        $service = new MailService(
            $this->createMock(IMailer::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(PermissionService::class),
            $ambiguous,
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
            $this->l10nFactory,
        );

        $l = $this->callGetL10nForEmail($service, 'shared@example.com');

        $this->assertStringContainsString('[default]', $l->t('Your booking has been confirmed.'));
    }

    /** Translators are reused per language rather than rebuilt per call. */
    public function testTranslatorsAreCachedPerLanguage(): void {
        $this->languageByEmail = ['pierre@example.com' => 'fr'];
        $service = $this->makeService();

        $this->callGetL10nForEmail($service, 'pierre@example.com');
        $this->callGetL10nForEmail($service, 'pierre@example.com');

        $this->assertSame(['fr'], $this->requestedLanguages);
    }

    /**
     * Without an IFactory (the constructor arg is optional so existing
     * instantiations keep working) mails must still render readable English
     * rather than throwing.
     */
    public function testWithoutFactoryFallsBackToPassThroughEnglish(): void {
        $service = new MailService(
            $this->createMock(IMailer::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(PermissionService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );

        $l = $this->callGetL10nForEmail($service, 'anyone@example.com');

        $this->assertSame('Your booking has been confirmed.', $l->t('Your booking has been confirmed.'));
        $this->assertSame('Room: Room X', $l->t('Room: %s', ['Room X']));
    }

    /** The whole point of #24: the mail body itself comes out translated. */
    public function testAcceptedBodyIsRenderedInTheRecipientLanguage(): void {
        $this->languageByEmail = ['pierre@example.com' => 'fr'];
        $service = $this->makeService();

        $l = $this->callGetL10nForEmail($service, 'pierre@example.com');
        $method = new \ReflectionMethod($service, 'buildAcceptedBody');
        $body = $method->invoke($service, $l, ['name' => 'Room X'], [
            'summary' => 'Weekly standup',
            'dtstartFormatted' => '2026-08-15 10:00',
            'dtendFormatted' => '11:00',
            'organizerName' => 'Pierre',
            'organizerEmail' => 'pierre@example.com',
        ]);

        $this->assertStringContainsString('[fr]', $body);
        $this->assertStringContainsString('Room X', $body, 'Values stay untranslated');
        $this->assertStringContainsString('Weekly standup', $body);
    }
}
