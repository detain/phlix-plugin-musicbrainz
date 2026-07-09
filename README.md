# phlix-plugin-musicbrainz

[![tests](https://github.com/detain/phlix-plugin-musicbrainz/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-musicbrainz/actions/workflows/test.yml)

> MusicBrainz metadata provider for [Phlix](https://github.com/detain/phlix-server) —
> enriches your music library with artist/album/track metadata, album art from
> the Cover Art Archive, and AcoustID fingerprint integration.

## Overview

Connects a Phlix server to [MusicBrainz](https://musicbrainz.org/) to enrich
music items with comprehensive metadata:

- **Artist metadata** — names, aliases, country,begin/end dates, genres, tags
- **Album metadata** — release date, label, catalog number, barcode, genres
- **Track metadata** — track number, disc number, duration, ISRC codes
- **Album artwork** — fetched from the Cover Art Archive API
- **AcoustID fingerprints** — acoustic identification for tracks

It subscribes to `phlix.media.metadata_enrich` and `phlix.library.scan_complete`
to enrich music items during library operations.

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

| Setting | Type | Description |
|---|---|---|
| `enabled` | bool | Enable MusicBrainz metadata enrichment. |
| `user_agent` | string | MusicBrainz API user agent (must include contact email). |
| `rate_limit_delay` | int | Milliseconds between API requests (default 1100, respects 1 req/s limit). |
| `auto_enrich` | bool | Automatically enrich music items during library scans. |
| `fetch_album_art` | bool | Fetch album artwork from Cover Art Archive. |
| `fetch_acoustid` | bool | Calculate and fetch AcoustID fingerprints. |
| `search_depth` | string | Search depth: `fast`, `normal`, or `deep`. |

## Development

```bash
composer install
vendor/bin/phpunit
```

The entry class is `Phlix\Plugin\MusicBrainz\MusicBrainzPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface`). It runs inside a Phlix server host,
which provides the library services at runtime.

## API Rate Limiting

MusicBrainz requires **no more than 1 request per second**. This plugin enforces
a configurable delay (default 1100ms) between all API calls. Please configure
a valid `user_agent` with contact information so MusicBrainz can reach you if
there are issues.

## License

MIT — see [LICENSE](LICENSE).
