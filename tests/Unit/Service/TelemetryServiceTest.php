<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\LicenseService;
use OCA\RoomVox\Service\RoomGroupService;
use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Service\TelemetryService;
use OCA\RoomVox\Tests\Unit\Support\InMemoryAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The usage-statistics report, checked against design TELEMETRY.md 1.1.0.
 */
class TelemetryServiceTest extends TestCase {
    use InMemoryAppConfig;

    /** Exactly the RoomVox fields of TELEMETRY.md §3, in the order they are sent. */
    private const SCHEMA_1_KEYS = [
        'instanceHash',
        'telemetrySchema',
        'appVersion',
        'nextcloudVersion',
        'phpVersion',
        'totalUsers',
        'hasValidSubscription',
        'activeUsers30d',
        'disabledUsers',
        'countryCode',
        'totalRooms',
        'totalRoomGroups',
        'autoAcceptCount',
        'roomsWithSmtp',
        'exchangeSyncEnabled',
        'roomsWithExchange',
    ];

    private IClientService $clientService;
    private IUserManager $userManager;
    private RoomService $roomService;
    /** @var array<string, mixed> */
    private array $systemValues = [];
    private array $rooms = [];

    protected function setUp(): void {
        $this->clientService = $this->createMock(IClientService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('getAllRooms')->willReturnCallback(fn () => $this->rooms);
        // An installation that agreed to field list 1; each test decides
        // whether the switch itself is on.
        $this->appValues = ['telemetry_consent_schema' => '1'];
        $this->readKeys = [];
        $this->systemValues = ['version' => '32.0.1.2'];
    }

    private function l10n(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, $params = []) => vsprintf($text, (array)$params));
        return $l;
    }

    private function consent(): TelemetryConsentService {
        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(function () {
            $n = $this->createMock(INotification::class);
            $n->method($this->anything())->willReturnSelf();
            return $n;
        });
        return new TelemetryConsentService(
            $this->createInMemoryAppConfig(),
            $notifications,
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function service(?string $class = null): TelemetryService {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            fn (string $key, $default = '') => $this->systemValues[$key] ?? $default
        );
        $license = $this->createMock(LicenseService::class);
        $license->method('getInstanceUrlHash')->willReturn(str_repeat('a', 64));

        $class ??= TelemetryService::class;
        return new $class(
            $this->clientService,
            $config,
            $this->createInMemoryAppConfig(),
            $this->l10n(),
            $this->createMock(LoggerInterface::class),
            $this->userManager,
            $this->roomService,
            $this->createMock(RoomGroupService::class),
            $license,
            $this->consent(),
        );
    }

    private function givenUsers(int $enabled, int $disabled = 0): void {
        $users = [];
        for ($i = 0; $i < $enabled + $disabled; $i++) {
            $user = $this->createMock(IUser::class);
            $user->method('isEnabled')->willReturn($i < $enabled);
            $users[] = $user;
        }
        $this->userManager->method('callForAllUsers')->willReturnCallback(function (\Closure $cb) use ($users) {
            foreach ($users as $user) {
                $cb($user);
            }
        });
    }

    // ── The payload ───────────────────────────────────────────────

    public function testCollectDataSendsExactlyTheSchemaOneKeys(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->assertSame(self::SCHEMA_1_KEYS, array_keys($this->service()->collectData()));
    }

    public function testSchemaConstantMatchesTheLastDefinedSchema(): void {
        $method = new \ReflectionMethod(TelemetryService::class, 'fieldsBySchema');
        $schemas = array_keys($method->invoke($this->service(), $this->l10n()));
        $this->assertSame(TelemetryService::SCHEMA, max($schemas),
            'A field was added without raising TelemetryService::SCHEMA');
    }

    public function testEveryFieldHasALabelAndAPurpose(): void {
        $definitions = $this->service()->getFieldDefinitions();
        $this->assertSame(self::SCHEMA_1_KEYS, array_keys($definitions));
        foreach ($definitions as $key => $definition) {
            $this->assertNotSame('', $definition['label'], $key);
            $this->assertNotSame('', $definition['purpose'], $key);
        }
    }

    public function testStatusListsTheSameFieldsAsThePayload(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $service = $this->service();
        $status = $service->getStatus();

        $this->assertSame(array_keys($service->collectData()), array_column($status['fields'], 'key'));
        $this->assertTrue($status['enabled']);
        $this->assertSame(1, $status['schema']);
    }

    public function testVersionsAndSchema(): void {
        $this->appValues['installed_version'] = '1.6.0';
        $data = $this->service()->collectData();

        $this->assertSame(1, $data['telemetrySchema']);
        $this->assertSame('1.6.0', $data['appVersion']);
        $this->assertSame('32.0.1.2', $data['nextcloudVersion']);
        $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $data['phpVersion']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $data['phpVersion']);
    }

    public function testTotalUsersIsTheRealCountWithoutAFloor(): void {
        $this->givenUsers(0);
        $data = $this->service()->collectData();
        $this->assertSame(0, $data['totalUsers']);
        $this->assertSame(0, $data['disabledUsers']);
    }

    public function testUserCountsIncludeDisabledAccounts(): void {
        $this->givenUsers(3, 2);
        $data = $this->service()->collectData();
        $this->assertSame(5, $data['totalUsers']);
        $this->assertSame(2, $data['disabledUsers']);
    }

    /** Missing is not zero (TELEMETRY.md §8). */
    public function testUserCountsAreNullWhenCountingFails(): void {
        $this->userManager->method('callForAllUsers')->willThrowException(new \RuntimeException('backend down'));
        $this->userManager->method('callForSeenUsers')->willThrowException(new \RuntimeException('backend down'));

        $data = $this->service()->collectData();
        $this->assertNull($data['totalUsers']);
        $this->assertNull($data['disabledUsers']);
        $this->assertNull($data['activeUsers30d']);
    }

    // ── countryCode ───────────────────────────────────────────────

    public function testCountryCodeFromPhoneRegion(): void {
        $this->systemValues['default_phone_region'] = 'nl';
        $this->systemValues['default_timezone'] = 'America/New_York';
        $this->assertSame('NL', $this->service()->collectData()['countryCode']);
    }

    public function testCountryCodeFromTimezoneWhenNoPhoneRegion(): void {
        $this->systemValues['default_timezone'] = 'Europe/Amsterdam';
        $data = $this->service()->collectData();

        $this->assertSame('NL', $data['countryCode']);
        $this->assertStringNotContainsString('Amsterdam', json_encode($data), 'the time zone itself must not be sent');
    }

    /** @return array<string, array{string}> */
    public static function timezonesWithoutCountry(): array {
        return [
            'UTC' => ['UTC'],
            'Etc zone' => ['Etc/GMT+1'],
            'invalid' => ['Not/AZone'],
            'unset' => [''],
        ];
    }

    /**
     * @dataProvider timezonesWithoutCountry
     */
    public function testCountryCodeIsNullWithoutACountry(string $timezone): void {
        $this->systemValues['default_timezone'] = $timezone;
        $this->assertNull($this->service()->collectData()['countryCode']);
    }

    // ── Exchange figures (against the real collectData) ───────────

    private function room(?array $exchangeConfig = null): array {
        $room = ['id' => 'r1', 'roomType' => 'meeting', 'capacity' => 4];
        if ($exchangeConfig !== null) {
            $room['exchangeConfig'] = $exchangeConfig;
        }
        return $room;
    }

    /**
     * The count has to agree with ExchangeSyncService::isExchangeRoom(): a
     * figure that disagrees with what actually syncs would be read as "nobody
     * uses this" right before the integration is dropped.
     */
    public function testRoomsWithExchangeCountsOnlyRoomsThatSync(): void {
        $this->rooms = [
            $this->room(['resourceEmail' => 'a@example.com', 'syncEnabled' => true]),
            $this->room(['resourceEmail' => 'b@example.com', 'syncEnabled' => true]),
            $this->room(['resourceEmail' => 'c@example.com', 'syncEnabled' => false]),
            $this->room(['resourceEmail' => '', 'syncEnabled' => true]),
            ['id' => 'r5', 'exchangeConfig' => null],
            $this->room(),
        ];
        $data = $this->service()->collectData();

        $this->assertSame(2, $data['roomsWithExchange']);
        $this->assertSame(6, $data['totalRooms']);
    }

    public function testRoomCounts(): void {
        $this->rooms = [
            ['id' => 'a', 'autoAccept' => true, 'smtpConfig' => ['host' => 'smtp.example.com']],
            ['id' => 'b', 'autoAccept' => false, 'smtpConfig' => ['host' => '']],
            ['id' => 'c', 'autoAccept' => true],
        ];
        $data = $this->service()->collectData();

        $this->assertSame(2, $data['autoAcceptCount']);
        $this->assertSame(1, $data['roomsWithSmtp']);
    }

    public function testExchangeFlagReadsExchangeEnabledAndDefaultsToFalse(): void {
        $this->assertFalse($this->service()->collectData()['exchangeSyncEnabled']);

        $this->appValues['exchange_enabled'] = 'true';
        $this->assertTrue($this->service()->collectData()['exchangeSyncEnabled']);
    }

    /**
     * The credentials live in appconfig right next to the flag. Reading any of
     * them while building the report would put them one careless array_merge
     * away from the payload.
     */
    public function testNeverReadsExchangeCredentials(): void {
        $this->appValues += [
            'exchange_enabled' => 'true',
            'exchange_tenant_id' => 'tenant-secret',
            'exchange_client_id' => 'client-secret',
            'exchange_client_secret' => 'very-secret',
        ];
        $data = $this->service()->collectData();

        foreach (['exchange_tenant_id', 'exchange_client_id', 'exchange_client_secret'] as $key) {
            $this->assertNotContains($key, $this->readKeys, "telemetry read the credential key {$key}");
        }
        $this->assertStringNotContainsString('secret', json_encode($data));
    }

    // ── Consent and sending ───────────────────────────────────────

    public function testMissingRowReadsAsOff(): void {
        $this->assertFalse($this->service()->isEnabled());
        $this->assertFalse($this->service()->getStatus()['enabled']);
    }

    public function testSendRefusesWhenOffAndDoesNotSwitchOn(): void {
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service()->sendReportWithDetails();

        $this->assertSame(['success' => false, 'reason' => 'disabled'], $result);
        $this->assertArrayNotHasKey('telemetry_enabled', $this->appValues);
    }

    public function testSendRefusesWhenExplicitlyOff(): void {
        $this->appValues['telemetry_enabled'] = 'false';
        $this->clientService->expects($this->never())->method('newClient');
        $this->assertSame('disabled', $this->service()->sendReportWithDetails()['reason']);
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }

    private function givenEndpointAnswers(int $status): void {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);
    }

    public function testSendsWhenOnAndRecordsTheTime(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->givenEndpointAnswers(201);

        $this->assertSame(['success' => true], $this->service()->sendReportWithDetails());
        $this->assertArrayHasKey('telemetry_last_report', $this->appValues);
    }

    public function testHttp429IsRecentlySent(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->givenEndpointAnswers(429);

        $this->assertSame(['success' => false, 'reason' => 'recently_sent'], $this->service()->sendReportWithDetails());
    }

    /** Guzzle throws on 4xx; the status is on the exception's response. */
    public function testHttp429ExceptionIsRecentlySent(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $errorResponse = $this->createMock(IResponse::class);
        $errorResponse->method('getStatusCode')->willReturn(429);
        $exception = new class('Too Many Requests', $errorResponse) extends \Exception {
            public function __construct(string $message, private object $response) {
                parent::__construct($message);
            }

            public function getResponse(): object {
                return $this->response;
            }
        };
        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException($exception);
        $this->clientService->method('newClient')->willReturn($client);

        $this->assertSame(['success' => false, 'reason' => 'recently_sent'], $this->service()->sendReportWithDetails());
    }

    public function testReportWithinTheHourIsNotSentAgain(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->appValues['telemetry_last_report'] = (string)(time() - 600);
        $this->clientService->expects($this->never())->method('newClient');

        $this->assertSame('recently_sent', $this->service()->sendReportWithDetails()['reason']);
    }

    // ── A later schema is withheld until agreed to ────────────────

    public function testFieldsOfALaterSchemaAreWithheldUntilAgreedTo(): void {
        // Telemetry switched on before this version, for schema 1.
        $this->appValues['telemetry_enabled'] = 'true';
        $this->appValues['telemetry_consent_schema'] = '1';
        $service = $this->service(TelemetryServiceWithLaterSchema::class);

        $this->assertNotContains('futureField', array_keys($service->collectData()));
        $this->assertSame(self::SCHEMA_1_KEYS, array_keys($service->collectData()));

        $status = $service->getStatus();
        $future = array_values(array_filter($status['fields'], fn ($f) => $f['key'] === 'futureField'));
        $this->assertCount(1, $future);
        $this->assertTrue($future[0]['withheld']);
    }
}

/**
 * Adds a schema-2 field, standing in for a field a future version adds.
 */
class TelemetryServiceWithLaterSchema extends TelemetryService {
    protected function fieldsBySchema(IL10N $l): array {
        $fields = parent::fieldsBySchema($l);
        $fields[2] = ['futureField' => ['label' => 'Future field', 'purpose' => 'Testing']];
        return $fields;
    }
}
