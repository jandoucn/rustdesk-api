# GeoLite2 City database

`GeoLite2-City.mmdb` is bundled as an image seed. On a fresh deployment the
container entrypoint copies it to the persistent path:

```text
/var/www/data/GeoLite2-City.mmdb
```

An existing persistent database is retained across image upgrades. To replace
it deliberately, run the installer with `RUSTDESK_GEOIP_SOURCE` pointing to the
new MMDB; the installer copies that file into the Docker data volume.

## Current artifact

- Imported: 2026-09-30
- Imported from: the operator-managed NTServer GeoLite database
- Database build time: 2026-08-09T12:31:41Z
- Database type / IP version: `GeoLite2-City` / IPv6-capable
- Size: 80,639,229 bytes
- SHA-256: `82e54e4cf2391b1a17f518dbf80e01fb522654285668602750e241fd8f885c96`

This product includes GeoLite2 data created by MaxMind, available from
<https://www.maxmind.com>. GeoLite2 database redistribution and attribution are
governed by MaxMind's current GeoLite2 terms and the Creative Commons
Attribution-ShareAlike 4.0 International License.
