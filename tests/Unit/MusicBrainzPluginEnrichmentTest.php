<?php

/**
 * MusicBrainzPlugin enrichment-path unit tests.
 *
 * Asserts consequences: the MediaItemAdded handler defers (no inline HTTP),
 * the deferred drain enriches and PERSISTS via ItemRepository::update(), and
 * onEnable performs zero HTTP.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;
use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MusicBrainzPluginEnrichmentTest extends TestCase
{
    private const VALID_UA = 'PhlixTest/1.0 (https://example.com/contact)';

    /**
     * A recording-match response set for the "fast" search depth.
     *
     * @return array<string, string>
     */
    private function recordingMatchResponses(): array
    {
        return [
            '/recording' => (string) json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-1',
                        'title' => 'Test Track',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'Test Artist', 'artist' => ['id' => 'art-1', 'name' => 'Test Artist']],
                        ],
                        'releases' => [['id' => 'rel-1']],
                    ],
                ],
            ]),
            '/release/rel-1' => (string) json_encode([
                'id' => 'rel-1',
                'title' => 'Test Album',
                'date' => '2024-01-01',
            ]),
        ];
    }

    /**
     * @param array<string, string> $responses
     */
    private function countingClient(array $responses = []): HttpClientInterface
    {
        return new class($responses) implements HttpClientInterface {
            public int $calls = 0;

            /** @param array<string, string> $responses */
            public function __construct(private array $responses)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                $this->calls++;
                foreach ($this->responses as $pattern => $body) {
                    if (str_contains($url, $pattern)) {
                        return $body;
                    }
                }
                return null;
            }
        };
    }

    private function container(?ItemRepository $repo): ContainerInterface
    {
        return new class($repo) implements ContainerInterface {
            public function __construct(private ?ItemRepository $repo)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ItemRepository::class) {
                    return $this->repo;
                }
                return new NullLogger();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
    }

    /**
     * Replace the plugin's API client (and its enricher) with a spy so the
     * underlying HTTP transport can be observed.
     */
    private function injectApi(MusicBrainzPlugin $plugin, MusicBrainzApi $api): void
    {
        $ref = new \ReflectionObject($plugin);

        $apiProp = $ref->getProperty('api');
        $apiProp->setAccessible(true);
        $apiProp->setValue($plugin, $api);

        $settingsProp = $ref->getProperty('settings');
        $settingsProp->setAccessible(true);
        $settings = $settingsProp->getValue($plugin);

        $loggerProp = $ref->getProperty('logger');
        $loggerProp->setAccessible(true);
        $logger = $loggerProp->getValue($plugin);
        $logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();

        $enrProp = $ref->getProperty('enricher');
        $enrProp->setAccessible(true);
        $enrProp->setValue($plugin, new MetadataEnricher($api, $settings, $logger));
    }

    private function enabledPlugin(): MusicBrainzPlugin
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => self::VALID_UA,
            'auto_enrich' => true,
            'fetch_album_art' => false,
            'search_depth' => 'fast',
            'rate_limit_delay' => 0,
        ]);

        return $plugin;
    }

    public function testHandlerDefersEnrichmentAndDoesNoInlineHttp(): void
    {
        $client = $this->countingClient($this->recordingMatchResponses());
        $plugin = $this->enabledPlugin();
        $this->injectApi($plugin, new MusicBrainzApi($client, self::VALID_UA, 0));
        $plugin->onEnable($this->container(null));

        $plugin->onMediaItemAdded(new MediaItemAdded('item-1', 'lib-1', '/music/a.flac', 'track'));

        // The handler must ONLY enqueue — no HTTP during the scan-driven event.
        $this->assertSame(1, $plugin->queueSize(), 'Item must be queued, not enriched inline.');
        $this->assertSame(0, $client->calls, 'Handler must not perform any HTTP inline.');
    }

    public function testNonMusicItemIsNotQueued(): void
    {
        $plugin = $this->enabledPlugin();
        $this->injectApi($plugin, new MusicBrainzApi($this->countingClient(), self::VALID_UA, 0));
        $plugin->onEnable($this->container(null));

        $plugin->onMediaItemAdded(new MediaItemAdded('movie-1', 'lib-1', '/movies/x.mkv', 'movie'));

        $this->assertSame(0, $plugin->queueSize());
    }

    public function testDrainEnrichesAndPersistsViaItemRepositoryUpdate(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Test Track',
            'type' => 'track',
            'path' => '/music/a.flac',
            'metadata' => ['artist' => 'Test Artist'],
        ]);

        // The persistence consequence: update() called once, writing a
        // metadata_json blob that carries the MusicBrainz enrichment.
        $repo->expects($this->once())
            ->method('update')
            ->with(
                $this->identicalTo('item-1'),
                $this->callback(function (array $data): bool {
                    return isset($data['metadata_json']['musicbrainz'])
                        && is_array($data['metadata_json']['musicbrainz'])
                        && ($data['metadata_json']['musicbrainz']['tracks']['title'] ?? null) === 'Test Track';
                })
            );

        $plugin = $this->enabledPlugin();
        $this->injectApi($plugin, new MusicBrainzApi($this->countingClient($this->recordingMatchResponses()), self::VALID_UA, 0));
        $plugin->onEnable($this->container($repo));

        $plugin->onMediaItemAdded(new MediaItemAdded('item-1', 'lib-1', '/music/a.flac', 'track'));

        // Deferred drain does the work and persists.
        $this->assertTrue($plugin->drainOne(), 'First drain must dispatch the queued item.');
        $this->assertSame(0, $plugin->queueSize());
    }

    public function testDrainDoesNotPersistWhenNoMatch(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Nonexistent Track',
            'type' => 'track',
            'path' => '/music/a.flac',
            'metadata' => [],
        ]);
        $repo->expects($this->never())->method('update');

        $plugin = $this->enabledPlugin();
        // Empty response set -> no MusicBrainz match -> nothing to persist.
        $this->injectApi($plugin, new MusicBrainzApi($this->countingClient([]), self::VALID_UA, 0));
        $plugin->onEnable($this->container($repo));

        $plugin->onMediaItemAdded(new MediaItemAdded('item-1', 'lib-1', '/music/a.flac', 'track'));
        $plugin->drainOne();
    }

    public function testOnEnablePerformsZeroHttp(): void
    {
        $client = $this->countingClient($this->recordingMatchResponses());
        $plugin = $this->enabledPlugin();
        $this->injectApi($plugin, new MusicBrainzApi($client, self::VALID_UA, 0));

        $plugin->onEnable($this->container(null));

        $this->assertSame(0, $client->calls, 'onEnable must not perform any HTTP (boot-safe wire step).');
    }
}
