<?php

declare(strict_types=1);

namespace Muxi\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Muxi\ServerClient;
use Muxi\ServerConfig;
use Muxi\FormationClient;
use Muxi\FormationConfig;

#[Group('integration')]
class IntegrationTest extends TestCase
{
    private static ?ServerClient $server = null;
    private static ?FormationClient $formation = null;

    private static function env(string $name): string
    {
        $val = getenv($name);
        if ($val === false || $val === '') {
            self::markTestSkipped("$name not set");
        }
        return $val;
    }

    public static function setUpBeforeClass(): void
    {
        $serverUrl = self::env('MUXI_SDK_E2E_SERVER_URL');
        $keyId = self::env('MUXI_SDK_E2E_KEY_ID');
        $secretKey = self::env('MUXI_SDK_E2E_SECRET_KEY');
        $formationId = self::env('MUXI_SDK_E2E_FORMATION_ID');
        $clientKey = self::env('MUXI_SDK_E2E_CLIENT_KEY');
        $adminKey = self::env('MUXI_SDK_E2E_ADMIN_KEY');

        self::$server = new ServerClient(new ServerConfig(
            url: $serverUrl,
            keyId: $keyId,
            secretKey: $secretKey
        ));

        self::$formation = new FormationClient(new FormationConfig(
            serverUrl: $serverUrl,
            formationId: $formationId,
            clientKey: $clientKey,
            adminKey: $adminKey
        ));
    }

    public function testServerPing(): void
    {
        $result = self::$server->ping();
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testServerHealth(): void
    {
        $result = self::$server->health();
        $this->assertIsArray($result);
    }

    public function testServerStatus(): void
    {
        $result = self::$server->status();
        $this->assertIsArray($result);
    }

    public function testServerListFormations(): void
    {
        $result = self::$server->listFormations();
        $this->assertIsArray($result);
    }

    public function testFormationHealth(): void
    {
        $result = self::$formation->health();
        $this->assertIsArray($result);
    }

    public function testFormationGetStatus(): void
    {
        $result = self::$formation->getStatus();
        $this->assertIsArray($result);
    }

    public function testFormationGetConfig(): void
    {
        $result = self::$formation->getConfig();
        $this->assertIsArray($result);
    }

    public function testFormationGetAgents(): void
    {
        $result = self::$formation->getAgents();
        $this->assertIsArray($result);
    }
}
