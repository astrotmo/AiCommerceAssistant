# Changelog

## 2.0.0 - vorbereitet am 2026-10-25

- **Breaking:** Der Shopware-Proxy authentifiziert Backend-Aufrufe jetzt mit einem
  kurzlebigen HS256-Service-JWT. `AICA_BACKEND_SECRET` muss in Shopware und Backend
  identisch sein und aus genau 64 kleinen Hex-Zeichen bestehen. Aufrufe ohne gültige
  Service-Authentifizierung werden vom Backend abgewiesen.
- Backend-401/403 werden im Proxy als `CHAT_BACKEND_AUTH_FAILED`/HTTP 502 gemeldet;
  Redirects werden nicht verfolgt. Die DI-Konfiguration registriert die Token-Factory.
- Seit dem ursprünglichen 1.0.0-Stand: ergänzte Architektur-, Datenschutz- und
  Betriebsdokumentation sowie Präzisierungen der Tracking-/Kontextintegration.
- Frischer Storefront-/Administration-Build, installierbares ZIP mit Herkunftsnachweis
  und SHA-256; CI und bewusst ausgelöste Artefaktaufbewahrung ohne Deployment.
- Shopware 6.7 mit Storefront und Administration; deklarierte PHP-Spanne 8.2–8.4.

Migration und manuelle Schritte: [docs/release.md](docs/release.md).
Es wurden für diese Vorbereitung keine neuen Datenbankmigrationen hinzugefügt.

## 1.0.0 - 2026-06-09

- Initial AICA – AI Commerce Assistant plugin release.
