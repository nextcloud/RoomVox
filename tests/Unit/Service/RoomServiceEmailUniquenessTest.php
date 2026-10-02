<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Exception\EmailAlreadyUsedException;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A room's email address is its scheduling identity: iMIP invitations,
 * accept/decline replies and the Exchange resource-mailbox link are keyed on it.
 * Two rooms sharing one address makes that routing ambiguous, so creating or
 * updating a room onto a taken address must be refused.
 */
class RoomServiceEmailUniquenessTest extends TestCase {
    private RoomService $service;
    private IAppConfig $appConfig;

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->service = new RoomService(
            $this->appConfig,
            $this->createMock(ICrypto::class),
            $this->createMock(ISecureRandom::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Back the service with a single existing room holding $email.
     */
    private function withExistingRoom(string $id, string $email): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) use ($id, $email) {
                if ($key === 'rooms_index') {
                    return json_encode([$id]);
                }
                if ($key === 'room/' . $id) {
                    return json_encode([
                        'id' => $id,
                        'userId' => 'rb_' . $id,
                        'name' => 'Existing room',
                        'email' => $email,
                        'smtpConfig' => null,
                    ]);
                }
                return $default;
            });
    }

    /** @var array<string, string> Keys written by the service */
    private array $written = [];

    /**
     * Back the service with several rooms and capture what it writes.
     *
     * @param array<string, string> $emailById room id → email
     */
    private function withRooms(array $emailById): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) use ($emailById) {
                if ($key === 'rooms_index') {
                    return json_encode(array_keys($emailById));
                }
                foreach ($emailById as $id => $email) {
                    if ($key === 'room/' . $id) {
                        return json_encode([
                            'id' => $id,
                            'userId' => 'rb_' . $id,
                            'name' => 'Room ' . $id,
                            'email' => $email,
                            'capacity' => 4,
                            'autoAccept' => false,
                            'active' => true,
                            'smtpConfig' => null,
                        ]);
                    }
                }
                return $default;
            });
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) {
                $this->written[$key] = $value;
                return true;
            });
    }

    /**
     * Rooms that shared an address before this check existed must stay
     * editable. The editor always sends the whole form, email included, so
     * checking an unchanged address refused every edit of them with a 409.
     */
    public function testUnchangedSharedAddressDoesNotBlockAnUpdate(): void {
        $this->withRooms(['room-a' => 'shared@example.com', 'room-b' => 'shared@example.com']);

        $updated = $this->service->updateRoom('room-a', ['email' => 'Shared@example.com', 'capacity' => 12]);

        $this->assertSame(12, $updated['capacity']);
    }

    public function testChangingOntoAnotherRoomsAddressIsStillRefused(): void {
        $this->withRooms(['room-a' => 'a@example.com', 'room-b' => 'b@example.com']);

        $this->expectException(EmailAlreadyUsedException::class);
        $this->service->updateRoom('room-a', ['email' => 'b@example.com']);
    }

    /**
     * Without an address of its own a room gets {id}@roomvox.local. When
     * another room holds that address by hand, the id moves on instead of
     * silently sharing it.
     */
    public function testGeneratedAddressSkipsOneThatIsTaken(): void {
        $this->withRooms([
            'meeting' => 'meeting@roomvox.local',
            'boardroom' => 'meeting-1@roomvox.local',
        ]);

        $room = $this->service->createRoom(['name' => 'Meeting']);

        $this->assertSame('meeting-2', $room['id']);
        $this->assertSame('meeting-2@roomvox.local', $room['email']);
    }

    public function testCreateRejectsTakenEmail(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        $this->expectException(EmailAlreadyUsedException::class);
        $this->service->createRoom(['name' => 'Second room', 'email' => 'shared@example.com']);
    }

    public function testCreateRejectsTakenEmailRegardlessOfCase(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        $this->expectException(EmailAlreadyUsedException::class);
        $this->service->createRoom(['name' => 'Second room', 'email' => 'SHARED@Example.COM']);
    }

    /**
     * Addresses reach us as "mailto:..." from CalDAV, so the comparison has to
     * see through that prefix rather than treat it as a different address.
     */
    public function testCreateRejectsTakenEmailWithMailtoPrefix(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        $this->expectException(EmailAlreadyUsedException::class);
        $this->service->createRoom(['name' => 'Second room', 'email' => 'mailto:shared@example.com']);
    }

    public function testExceptionNamesTheConflictingRoom(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        try {
            $this->service->createRoom(['name' => 'Second room', 'email' => 'shared@example.com']);
            $this->fail('Expected EmailAlreadyUsedException');
        } catch (EmailAlreadyUsedException $e) {
            $this->assertSame('room1', $e->getConflictingRoomId());
            $this->assertSame('shared@example.com', $e->getEmail());
        }
    }

    public function testFindRoomByEmailIgnoresTheRoomItself(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        $this->assertNull($this->service->findRoomByEmail('shared@example.com', 'room1'));
        $this->assertNotNull($this->service->findRoomByEmail('shared@example.com'));
    }

    public function testFindRoomByEmailReturnsNullForFreeAddress(): void {
        $this->withExistingRoom('room1', 'shared@example.com');

        $this->assertNull($this->service->findRoomByEmail('free@example.com'));
    }

    /**
     * An empty address is not a conflict — rooms without one get a generated
     * address derived from their unique id.
     */
    public function testFindRoomByEmailTreatsEmptyAddressAsFree(): void {
        $this->withExistingRoom('room1', '');

        $this->assertNull($this->service->findRoomByEmail(''));
    }
}
