<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

final class ServerDiagnostics
{
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%kernel.cache_dir%')] private string $cacheDir,
        #[Autowire('%kernel.logs_dir%')] private string $logDir,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
    }

    public function report(): array
    {
        $checks = [];
        $checks['php'] = $this->check('PHP runtime', function (): string {
            if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 2) {
                throw new \RuntimeException('Expected PHP 8.2 to match the reference project.');
            }
            return PHP_VERSION.' · '.PHP_SAPI;
        });
        $checks['extensions'] = $this->check('PHP extensions', function (): string {
            $required = ['ctype', 'curl', 'dom', 'fileinfo', 'iconv', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip'];
            $missing = array_values(array_filter($required, fn (string $name): bool => !extension_loaded($name)));
            if ($missing !== []) {
                throw new \RuntimeException('Missing: '.implode(', ', $missing));
            }
            return count($required).' required extensions loaded';
        });
        $checks['database'] = $this->check('Database', function (): string {
            try {
                $this->connection->fetchOne('SELECT 1');
                $version = (string) $this->connection->fetchOne('SELECT VERSION()');
            } catch (\Throwable) {
                throw new \RuntimeException('Connection failed. Check DB_* settings, network access and pdo_mysql.');
            }
            if (!str_contains($version, 'MariaDB') || !str_starts_with($version, '10.11.')) {
                throw new \RuntimeException('Connected, but expected MariaDB 10.11.');
            }
            return $version.' · SELECT 1 passed';
        });
        $checks['cache'] = $this->check('Application cache', function (): string {
            $key = 'server_test_'.bin2hex(random_bytes(8));
            try {
                $item = $this->cache->getItem($key);
                $item->set($key)->expiresAfter(60);
                if (!$this->cache->save($item) || $this->cache->getItem($key)->get() !== $key) {
                    throw new \RuntimeException('Cache write/read failed.');
                }
            } finally {
                $this->cache->deleteItem($key);
            }
            return 'Write, read and delete passed';
        });
        $checks['filesystem'] = $this->check('Writable directories', function (): string {
            foreach ([$this->cacheDir, $this->logDir] as $directory) {
                if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Cannot create cache or log directory.');
                }
                $file = $directory.'/.server-test-'.bin2hex(random_bytes(8));
                try {
                    if (@file_put_contents($file, 'ok') !== 2 || @file_get_contents($file) !== 'ok') {
                        throw new \RuntimeException('Cache or log directory is not writable.');
                    }
                } finally {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            return 'Cache and log file write/read passed';
        });
        $checks['logging'] = $this->check('Application logger', function (): string {
            $this->logger->info('Server configuration diagnostic completed.');
            return 'Info message accepted; inspect the configured log destination';
        });

        return [
            'status' => !array_filter($checks, static fn (array $check): bool => $check['status'] !== 'ok') ? 'ok' : 'error',
            'checked_at' => gmdate(DATE_ATOM),
            'symfony' => Kernel::VERSION,
            'environment' => $this->environment,
            'checks' => $checks,
        ];
    }

    private function check(string $label, callable $probe): array
    {
        $start = microtime(true);
        try {
            $detail = $probe();
            $status = 'ok';
        } catch (\Throwable $error) {
            $status = 'error';
            // Only deliberately written diagnostic messages are safe to display.
            $detail = $error instanceof \RuntimeException && get_class($error) === \RuntimeException::class
                ? $error->getMessage()
                : 'Check failed. Inspect application logs for configuration errors.';
        }

        return ['label' => $label, 'status' => $status, 'detail' => $detail, 'duration_ms' => round((microtime(true) - $start) * 1000, 2)];
    }
}
