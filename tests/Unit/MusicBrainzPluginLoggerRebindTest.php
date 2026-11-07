<?php

/**
 * Logger rebind reaches the API client too.
 *
 * onEnable() resolves the host logger from the container and rebuilt the
 * enricher with it — but the MusicBrainzApi instance keeps living beside the
 * enricher (the enricher delegates transport-level work to it), and its
 * logger was baked at construction as a NullLogger. Every API-layer warning
 * (refused image URLs, rate-limit backoff, malformed upstream payloads) was
 * therefore silently dropped in the resident worker even though the plugin
 * itself "had" a real logger. The rebind must rebuild the api (or hand it
 * the logger) whenever the plugin's logger changes; these pins assert the
 * three-way identity after enable.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionObject;

final class MusicBrainzPluginLoggerRebindTest extends TestCase
{
    /** The one sink every layer must end up sharing. */
    private function hostLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function emergency($message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
            }
        };
    }

    private function containerWith(LoggerInterface $logger): ContainerInterface
    {
        return new class ($logger) implements ContainerInterface {
            public function __construct(private LoggerInterface $logger)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === LoggerInterface::class) {
                    return $this->logger;
                }

                return null;
            }

            public function has(string $id): bool
            {
                return $id === LoggerInterface::class;
            }
        };
    }

    /** @return array<array-key, mixed> */
    private function privateProp(object $owner, string $name): mixed
    {
        $property = (new ReflectionObject($owner))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($owner);
    }

    private function enabledPluginWith(LoggerInterface $logger): MusicBrainzPlugin
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'PhlixTest/1.0 (https://example.com/contact)',
            'rate_limit_delay' => 0,
        ]);
        $plugin->onEnable($this->containerWith($logger));

        return $plugin;
    }

    public function testApiCarriesTheHostLoggerAfterEnable(): void
    {
        $logger = $this->hostLogger();
        $plugin = $this->enabledPluginWith($logger);

        $api = $this->privateProp($plugin, 'api');
        $this->assertInstanceOf(MusicBrainzApi::class, $api);

        $apiLogger = $this->privateProp($api, 'logger');
        $this->assertSame($logger, $apiLogger, 'The API client must log to the container logger, not a NullLogger.');
    }

    public function testAllThreeLayersShareOneLogger(): void
    {
        $logger = $this->hostLogger();
        $plugin = $this->enabledPluginWith($logger);

        $enricher = $this->privateProp($plugin, 'enricher');
        $this->assertInstanceOf(MetadataEnricher::class, $enricher);

        $this->assertSame($logger, $this->privateProp($plugin, 'logger'));
        $this->assertSame($logger, $this->privateProp($enricher, 'logger'));
        $this->assertSame($logger, $this->privateProp($this->privateProp($plugin, 'api'), 'logger'));
    }

    public function testPluginLoggerIsNeverTheSilentDefaultAfterEnable(): void
    {
        $plugin = $this->enabledPluginWith($this->hostLogger());

        $apiLogger = $this->privateProp($this->privateProp($plugin, 'api'), 'logger');

        $this->assertNotInstanceOf(NullLogger::class, $apiLogger);
    }

    public function testEnableWithoutContainerLoggerKeepsWorking(): void
    {
        // Defensive shape: a container that answers a non-logger must not be
        // forced into the api; the NullLogger posture stays legal.
        $plugin = new MusicBrainzPlugin(new MusicBrainzSettings());
        $plugin->configure(['enabled' => true, 'user_agent' => 'PhlixTest/1.0 (https://example.com/contact)']);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $plugin->onEnable($container);

        $this->assertInstanceOf(MusicBrainzApi::class, $this->privateProp($plugin, 'api'));
    }
}
