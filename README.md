# phlix-plugin-musicbrainz

[![tests](https://github.com/detain/phlix-plugin-musicbrainz/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-musicbrainz/actions/workflows/test.yml)

> MusicBrainz metadata provider for [Phlix](https://github.com/detain/phlix-server) —
> enriches your music library with artist/album/track metadata and album art from
> the [Cover Art Archive](https://coverartarchive.org/).

## Overview

Connects a Phlix server to [MusicBrainz](https://musicbrainz.org/) to enrich
music items with comprehensive metadata:

- **Artist metadata** — name, sort name, aliases, type, country, begin/end
  dates and areas, disambiguation, tags, genres
- **Album metadata** — release date, country, label, catalog number, barcode,
  media format, ASIN, full artist credit (featured artists and join phrases)
- **Track metadata** — track number, position, duration (`duration_ms`,
  milliseconds — the host's own `duration` is seconds), ISRC codes, full
  artist credit
- **Album artwork** — the front cover is fetched from the Cover Art Archive;
  item metadata persists an `album_art` presence marker (the image bytes
  themselves need a dedicated host-side artwork ingestion hook)

It subscribes to `phlix.library.item.added` and enqueues each item in
`src/EnrichmentQueue.php`, so enrichment runs deferred and throttled instead of
inline during a library scan.

## Install

From the Phlix admin **Plugins** section, paste this repo's URL:

```
https://github.com/detain/phlix-plugin-musicbrainz
```

…or from the CLI:

```bash
php bin/phlix plugin:install https://github.com/detain/phlix-plugin-musicbrainz
```

## Settings

All settings are optional — MusicBrainz is an open service, so no API key is required.

| Setting | Type | Default | Description |
|---|---|---|---|
| `enabled` | boolean | `false` | Master on/off for MusicBrainz enrichment. Optional; default off. No API key needed — MusicBrainz is an open service. |
| `user_agent` | string | `PhlixMusicBrainzPlugin/0.4.0 (…)` | Identifies Phlix to MusicBrainz (their policy REQUIRES a descriptive User-Agent with contact info). ENFORCED: enrichment only runs when the value contains a URL (the '://' substring) — an email-only UA fails the gate; the plugin declines to enqueue or enrich and logs one warning. Include a project/contact URL. Optional — the default is fine; customize if you run a fork. |
| `rate_limit_delay` | integer | `1100` | Minimum delay between MusicBrainz requests. Optional; default 1100 (MusicBrainz asks for no more than ~1 request/second). Values below 1000 are raised to the 1000 ms ToS floor; values above 60000 are capped at the 60000 ms ceiling; values that are not positive whole numbers fall back to the 1100 default. |
| `auto_enrich` | boolean | `true` | Automatically enrich music items as they are scanned. Enrichment runs through a throttled queue (one item per rate-limit interval, up to 10000 waiting), so large scans fill in gradually. Optional; default on. |
| `fetch_album_art` | boolean | `true` | Pull cover art via the Cover Art Archive. Optional; default on. |
| `search_depth` | string | `normal` | How hard to search for matches: fast, normal or deep. Fast skips the release and artist fallback searches; normal also searches releases; deep also searches artists when none was found, costing extra requests. Optional; default normal. |

The `user_agent` policy mirrors the [MusicBrainz API etiquette](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting) rules. The Description cells
above are byte-identical to the `plugin.json` manifest strings the admin UI
renders — the mirror is pinned by `PluginManifestReadmeMirrorTest`.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.neon   # level 9, zero baseline entries
vendor/bin/phpcs src --standard=PSR12
# audits the whole composer.lock (require AND require-dev); the composer-audit CI job runs this
php scripts/security-audit-check.php
```

Requirements: PHP >= 8.3 with `ext-curl` (declared in `composer.json`) — curl
is the documented CLI/test fallback transport only; inside the resident
Workerman server process the plugin uses the host's `Workerman\Http\Client`
instead (soft dependency, `class_exists`-guarded). Host-supplied classes the
plugin talks to through the DI container (`Phlix\Media\Library\*`,
`Workerman\Http\Client`, `Workerman\Timer`) are deliberately not composer
dependencies; PHPStan resolves them through verbatim-shape stubs under
`tests/Stub/` wired via `bootstrapFiles`, so the level 9 gate runs clean with
no `ignoreErrors` and no baseline file (M-MUSICBRAINZ-10/21).

The entry class is `Phlix\Plugin\MusicBrainz\MusicBrainzPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface` and
`Phlix\Shared\Plugin\ConfigurableInterface`). It runs inside a Phlix server host,
which provides the library services at runtime.

## API Rate Limiting

MusicBrainz requires **no more than 1 request per second**. This plugin enforces
a configurable delay (default 1100ms) between all API calls, and
`src/EnrichmentQueue.php` releases at most one queued item per interval (floor of
1s) so the budget holds across a whole scan. Please configure a valid
`user_agent` with contact information so MusicBrainz can reach you if there are
issues. The gate is enforced, not advisory: a `user_agent` without a URL (the
`://` substring) makes `isConfigured()` false — an email-only UA fails the gate;
the plugin declines to enqueue or enrich and logs one warning. Put a project or contact URL
in the UA; add your email alongside it if you like.

## License

MIT — see [LICENSE](LICENSE).
