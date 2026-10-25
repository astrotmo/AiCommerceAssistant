# Release-Vorbereitung: AICA 2.0.0

Stand 25.10.2026, Branch `stage`, vorgeschlagener Tag `v2.0.0`.
Lokal und im erreichbaren Remote existieren keine Tags oder GitHub-Releases.
`1.0.0` ist daher die bisherige Metadaten-/Changelog-Basis, kein belegter
veröffentlichter Release. Die neue verpflichtende Service-Authentifizierung
ändert den Betriebsvertrag inkompatibel und begründet den Major-Sprung.

## Manuelle Installation / Migration

1. Backend 0.2.0 und Plugin 2.0.0 gemeinsam für ein Wartungsfenster prüfen.
2. Vor dem Update `AICA_BACKEND_SECRET` in beiden Laufzeitumgebungen identisch
   bereitstellen: genau 64 kleine Hex-Zeichen. Kein Browser-/Plugin-Konfigurationswert.
   Diese Vorbereitung ändert keine Secrets. Das bisherige `CHAT_AUTH_SECRET`
   bleibt für den separaten Storefront-Kontexttoken erforderlich und ersetzt das
   Backend-Secret nicht. Backend und Shopware mit synchronisierten Uhren betreiben.
3. Datenbank und Dateien sichern. ZIP über den Shopware-Plugin-Manager installieren
   bzw. aktualisieren; bei CLI-Installation `plugin:refresh`, `plugin:update
   AiCommerceAssistant`, `assets:install`, `theme:compile` und Cache-Schritte im
   Zielsystem bewusst ausführen. Bei Erstinstallation stattdessen `plugin:install
   --activate AiCommerceAssistant`. Diese Schritte wurden ausschließlich in der isolierten lokalen QA-Instanz
   durchgeführt; im Zielsystem bleiben sie bewusst auszuführen.
4. Chat, anonyme/angemeldete Preisansichten, Kontaktmail und Administration prüfen.
   Die bestehende Migration `Migration1781000000CreateAicaPersistence` muss bei
   Installation/Update berücksichtigt werden. Es gibt keine neue Release-Migration.

Ein Rückwechsel auf 1.0.0-Plugin-Code ist mit einem Backend, das Service-JWTs
verlangt, nicht kompatibel. Code, Konfiguration und nötigenfalls Datenbanksicherung
als zusammengehörigen Rückfallstand behandeln.

## Paket / Workflow

Mit Python 3.12, Node 22.14.0, Shopware CLI 0.19.5 (Buildziel 6.7.3.1):

```bash
python3 scripts/package.py
```

`dist/AiCommerceAssistant-2.0.0.zip` enthält den technischen Plugin-Ordner,
PHP/Twig/SCSS/JS-Quellen, frisch gebaute Storefront-/Administration-Dateien samt
Vite-Entrypoints, Versionsmetadaten und `BUILD-INFO.json`. Keine externen
PHP-Laufzeitbibliotheken sind nötig; Shopware stellt die deklarierten Abhängigkeiten.
`marked` und `dompurify` werden aus dem vorhandenen npm-Lock in das Storefront-Bundle
gebaut. Source Maps, npm-Verzeichnisse, Tests, lokale Konfiguration und Secrets
werden nicht paketiert. GitHubs automatisches Source-ZIP enthält keine Bundles.

`.github/workflows/build-package.yml` führt auf relevanten `stage`-Pushes und PRs
Prüfungen/Test-Builds aus. Nur passende `vX.Y.Z`-Tags oder ein manueller Lauf mit
`package=true` bewahren ZIP und Prüfsumme als Actions-Artefakt für 30 Tage auf.
Tag und `composer.json` müssen übereinstimmen. Ein Tag darf auf dem aktuellen
`stage`-Commit liegen; ein Merge auf `main` erfolgt nicht automatisch.
Kein Release-Upload, kein Registry-Push und kein Deployment. Keine Secrets nötig.

Ein manueller `workflow_dispatch`-Start ist auf GitHub erst verfügbar, wenn
die Workflow-Datei auch im Default-Branch `main` existiert. Die AICA-Workflows
sind hier auf `stage` vorbereitet; passende Tag-Pushes funktionieren unabhängig
von einer späteren Übernahme nach `main`.

## Prüfgrenzen

Composer-Validierung, PHP-Syntax, JSON/XML-Parsing und frischer Asset-Build
sind erfolgreich. Paketinstallation, Update, Migrationen und Theme-Kompilierung werden in einer
separaten lokalen QA-Instanz mit eigener Datenbank und synthetischen Daten geprüft.
Der abschließende Paket-/Prüfsummennachweis und die Releasebewertung stehen im
Workspace-Bericht `RELEASE_PREPARATION.md`; die Entwicklungsinstanz bleibt unverändert.

Die zusätzliche Shopware-CLI-Prüfung `extension validate --only builtin` des
Pakets meldet fehlende **Store-Veröffentlichungsmetadaten**: Autoren,
übersetzte Beschreibungen, Hersteller-/Supportlinks und Plugin-Icon. Beim Theme
fehlt außerdem `previewMedia`; der Snippet-Checker meldet Warnungen zur Aufteilung
mehrerer Snippet-Dateien. Das sind offene Punkte für eine spätere Store-Einreichung;
es wird keine erfolgreiche Store-Zulassung behauptet. XML-Routen/-Services werden
vom aktuellen CLI als veraltet markiert; sie bestehen im verwendeten Shopware
6.7.3.1 den Container-/Twig-Prüflauf. Keine fremden Identitäts-/Supportangaben
wurden erfunden und keine Veröffentlichung ergänzt.

## Isolierter Paketnachweis

Das ZIP wurde über `plugin:zip-import` neu installiert sowie von der verfügbaren
1.0.0-Version aus Commit `78581fa` auf 2.0.0 aktualisiert. Das alte Paket wurde aus
diesen Git-Quellen mit frisch gebauten Assets erstellt; ein veröffentlichter alter
Release wird nicht behauptet. Synthetische Sitzungs-, Nachrichten-, Kontakt- und
Produktreferenzdaten sowie Konfiguration bleiben beim Update erhalten.

Mit dem Backend-Runtime-Paket 0.2.0, eigener lesender Shopware-Integration und einer
separaten Kopie des lokalen Modells `granite4.1:8b` wurde die Anfrage aus dem echten
Browser-Widget über Proxy, Service-JWT, Backend, Modell und MCP bis zur gerenderten
Produktantwort geprüft. Dieser Test verwendet keine simulierte Modellantwort.
Die HMAC-Signatur des separaten Kontext-JWTs wird geprüft; er gewährt weiterhin
keine Backend-Berechtigung. Ungültige/fehlende Service-Authentifizierung wird
abgewiesen. Finale Paketprüfsummen und Nachweise stehen im Workspace-Releasebericht.
