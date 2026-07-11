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

All settings are optional — MusicBrainz is an open service, so no API key is required.

| Setting | Type | Default | Description |
|---|---|---|---|
| `enabled` | boolean | `false` | Master on/off for MusicBrainz enrichment. Optional; default off. No API key needed — MusicBrainz is an open service. |
| `user_agent` | string | `PhlixMusicBrainzPlugin/0.1.0 (…)` | Identifies Phlix to MusicBrainz (their policy REQUIRES a descriptive User-Agent with contact info). Optional — the default is fine; customise if you run a fork. See [MusicBrainz API etiquette](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting). |
| `rate_limit_delay` | integer | `1100` | Minimum delay between MusicBrainz requests (ms). Optional; default 1100 (MusicBrainz asks for no more than ~1 request/second). |
| `auto_enrich` | boolean | `true` | Automatically enrich music items as they are scanned. Optional; default on. |
| `fetch_album_art` | boolean | `true` | Pull cover art via the Cover Art Archive. Optional; default on. |
| `fetch_acoustid` | boolean | `true` | Use AcoustID audio fingerprints to improve matching. Optional; default on. |
| `search_depth` | string | `normal` | How hard to search for matches (e.g. normal / deep). Optional; default normal. |

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
