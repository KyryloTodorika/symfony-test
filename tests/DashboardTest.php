<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    private function browser(bool $databaseAvailable = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $connection = $this->createMock(Connection::class);
        if ($databaseAvailable) {
            $connection->method('fetchOne')->willReturnCallback(
                static fn (string $sql): string|int => $sql === 'SELECT 1' ? 1 : '10.11.14-MariaDB',
            );
        } else {
            $connection->method('fetchOne')->willThrowException(new \RuntimeException('password=private-value'));
        }
        static::getContainer()->set('doctrine.dbal.default_connection', $connection);
        return $client;
    }

    public function testDashboardAndSessionPersistAcrossRedirectAndReload(): void
    {
        $client = $this->browser();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(6, '.card');
        self::assertSelectorTextContains('.summary', 'All server checks passed');
        $client->submit($crawler->selectButton('Test session')->form());
        self::assertResponseRedirects('/', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.session-count', '1');
        $client->request('GET', '/');
        self::assertSelectorTextContains('.session-count', '1');
        $client->request('POST', '/session', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testHealthyJsonHasNoSessionCookie(): void
    {
        $client = $this->browser();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $data['status']);
        self::assertSame('ok', $data['checks']['database']['status']);
        self::assertCount(6, $data['checks']);
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testDatabaseFailureIsReportedWithoutCredentials(): void
    {
        $client = $this->browser(false);
        $client->request('GET', '/health');
        self::assertResponseStatusCodeSame(503);
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $data['status']);
        self::assertSame('error', $data['checks']['database']['status']);
        self::assertStringNotContainsString('private-value', $client->getResponse()->getContent());
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.summary', 'Some checks need attention');
    }
}
