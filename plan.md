# Lernprojekt: Supabase mit PHP auf World4You

Stand: 05.11.2026

## Aktueller Projektstand

- Versionsverwaltung: Git, Branch `main`, Remote `origin` unter https://github.com/meuse24/sb.git. Anwendungscode, SQL und Dokumentation werden hier versioniert; Zugangsdaten bleiben lokal.
- Supabase-Projekt laut Benutzer angelegt; Projekt-URL und Publishable Key liegen lokal in `supabase.txt` vor.
- Lokaler HTTPS-Test gegen `/auth/v1/settings` mit dem Publishable Key erfolgreich: HTTP 200.
- E-Mail-Anmeldung ist aktiviert; E-Mail-Adressen werden nicht automatisch bestätigt. Für den ersten Login einen bestätigten Testbenutzer im Dashboard anlegen.
- Dieser Test lief vom Entwicklungsrechner aus. Die Verbindung vom World4You-Webspace und die Datenbankrechte wurden damit noch nicht geprüft.
- `.gitignore` schließt `ftp.txt`, `supabase.txt` und lokale Anwendungskonfiguration aus. Beim Datei-Upload diese Dateien ebenfalls ausschließen; `.gitignore` schützt nicht vor FTP-Uploads.

## Ziel

Eine kleine Testwebsite mit Anmeldung, Abmeldung, geschütztem Bereich und einem bearbeitbaren Benutzerprofil erstellen. Die Website läuft mit PHP bei World4You und wird per Dateiübertragung bereitgestellt. Supabase übernimmt Authentifizierung und PostgreSQL. Jeder Schritt soll nachvollziehbar erklärt und einzeln ausprobiert werden.

Erste Version: E-Mail/Passwort-Login und eigene Profildaten. Eine Verwaltungsoberfläche für fremde Benutzer oder Administratorrollen gehört noch nicht dazu. Testbenutzer werden zunächst im Supabase-Dashboard angelegt.

## Geprüfte Ausgangslage

Die öffentlich erreichbare Seite https://meuse24.info/phpinfo.php wurde am 05.11.2026 abgerufen.

| Eigenschaft | Befund | Bedeutung |
| --- | --- | --- |
| PHP | 8.4.26 | Für das Projekt geeignet |
| cURL | Aktiviert, Version 8.22.0 | PHP kann grundsätzlich HTTP-Anfragen ausführen |
| OpenSSL | Aktiviert | Grundlage für verschlüsselte Verbindungen vorhanden |
| PHP-Sessions | Modul und dateibasierter Handler vorhanden; Cookies aktiviert | Voraussetzungen für serverseitige Sessions vorhanden |
| Session-Autostart | Aus | Die Anwendung startet Sessions selbst |
| PDO-Treiber | mysql, sqlite | Kein PostgreSQL-PDO-Treiber aufgeführt |
| Lokale FTP-Zugangsdaten | `ftp.txt` vorhanden und nicht leer | Bei der späteren Bereitstellung verwenden |

Noch offen: ausgehende HTTPS-Verbindung vom World4You-Webspace zum konkreten Supabase-Projekt, Schreibbarkeit des Session-Verzeichnisses im praktischen Betrieb, Zielverzeichnis und verfügbare verschlüsselte Dateiübertragung. Aus phpinfo allein folgt noch kein erfolgreicher Verbindungstest zu Supabase.

Zugangsdaten werden nicht in diesen Plan kopiert. `ftp.txt`, lokale Konfiguration und Datenbankpasswort gehören weder ins Repository noch in einen öffentlich erreichbaren Upload. Die phpinfo-Datei nach Abschluss der Diagnose entfernen oder vor öffentlichem Zugriff schützen, da sie interne Konfiguration offenlegt.

## Architektur und Begriffe

```text
Browser
  | HTTPS: Formular und Session-Cookie
  v
PHP bei World4You
  | HTTPS: Auth-API und Daten-API
  v
Supabase Cloud
  +-- Auth: Benutzer, Passwortprüfung, Tokens
  +-- PostgreSQL: eigene Profildaten und Zugriffsregeln
```

- Supabase wird als gehosteter Dienst genutzt; auf dem Webspace wird kein Supabase-Server installiert.
- PHP ruft Supabase per cURL über HTTPS auf. Ein direkter PostgreSQL-Zugang ist dafür nicht erforderlich.
- Die PHP-Session verbindet den Browser mit dem PHP-Backend. Im Browser liegt nur die zufällige Session-ID als Cookie.
- Die Supabase-Session besteht aus Access Token und Refresh Token. Diese bleiben in der serverseitigen PHP-Session.
- Das Access Token weist gegenüber Supabase den angemeldeten Benutzer nach. Das Refresh Token dient zur Erneuerung ablaufender Tokens.
- Ein Projektschlüssel identifiziert die Anwendung, nicht den Benutzer. Für normale Benutzerzugriffe werden Publishable Key und Benutzer-Access-Token verwendet.
- Row Level Security (RLS) legt auf Datenbankebene fest, welche Zeilen ein Benutzer lesen oder ändern darf.

## Schritt 1: Supabase-Konto und Projekt anlegen

- [ ] Ein Konto unter https://supabase.com erstellen.
- [ ] Eine Organisation im Free-Tarif und darin ein Projekt anlegen, beispielsweise `php-login-test`.
- [ ] Eine angebotene EU-Region wählen, möglichst nahe am Hostingstandort.
- [ ] Ein starkes Datenbankpasswort im Passwortmanager speichern. Für die geplanten HTTPS-Aufrufe benötigt die Website dieses Passwort nicht.
- [ ] Projekt-URL und Publishable Key aus dem Dashboard für die lokale Anwendungskonfiguration bereithalten.
- [ ] E-Mail/Passwort als Anmeldeverfahren prüfen.
- [ ] Zunächst einen bestätigten Testbenutzer im Dashboard anlegen; später einen zweiten für die Zugriffstests.

Der Free-Tarif reicht für den Einstieg. Laut aktueller Preisübersicht können Free-Projekte nach einer Woche Inaktivität pausiert werden. Projektpasswort, Konto-Passwort und privilegierte Secret-Keys müssen nicht im Chat geteilt werden.

Lernziel: Supabase-Konto, Projekt, Anwendungsbenutzer und Datenbankbenutzer unterscheiden können.

## Schritt 2: PHP-Grundgerüst und Verbindung prüfen

- [ ] Ein eigenes Unterverzeichnis für die Testwebsite auf dem Webspace festlegen, damit vorhandene Inhalte erhalten bleiben.
- [ ] Kleine PHP-Anwendung ohne Framework und ohne Build-Prozess vorbereiten.
- [ ] Eine zentrale cURL-Funktion für JSON-Anfragen schreiben: Zertifikatsprüfung, Verbindungs- und Gesamtzeitlimit, HTTP-Status und Fehlerbehandlung.
- [ ] Supabase-Konfiguration außerhalb des öffentlichen Webverzeichnisses ablegen und aus PHP laden. Den tatsächlichen Webspace-Aufbau vorher prüfen.
- [ ] Einen begrenzten Verbindungstest vom Webspace aus durchführen. Erwarteten HTTP-Status und Antwortstruktur prüfen; keine Tokens oder Zugangsdaten ausgeben.
- [ ] Session-Cookie über zwei aufeinanderfolgende Seitenaufrufe praktisch prüfen.

Lernziel: HTTP-Anfrage, JSON-Antwort, API-Schlüssel und PHP-Session verstehen.

## Schritt 3: Login, geschützte Seite und Logout

- [ ] Ein E-Mail/Passwort-Formular erstellen und per POST an PHP senden.
- [ ] Formulare mit CSRF-Token schützen; Eingaben prüfen und Ausgaben HTML-escapen.
- [ ] PHP meldet den Benutzer über die Supabase-Auth-API an. Passwörter weder lokal speichern noch protokollieren.
- [ ] Nach erfolgreicher Anmeldung die PHP-Session-ID erneuern und Tokens sowie Ablaufzeit serverseitig speichern.
- [ ] Session-Cookies mit `Secure`, `HttpOnly` und einem passenden `SameSite`-Wert setzen; nur Cookies als Session-Transport verwenden.
- [ ] Eine gemeinsame Funktion zum Prüfen der Anmeldung und zum rechtzeitigen Erneuern ablaufender Access Tokens erstellen.
- [ ] Nach einer Erneuerung sowohl Access Token als auch Refresh Token aktualisieren. Parallele Anfragen berücksichtigen, damit nicht mehrfach dasselbe Refresh Token verwendet wird.
- [ ] Bei fehlgeschlagener Erneuerung die lokale Session beenden und erneut zur Anmeldung führen.
- [ ] Eine geschützte Seite serverseitig absichern; nicht allein anhand ausgeblendeter Links oder eines aus dem Browser übergebenen Benutzerobjekts entscheiden.
- [ ] Logout als geschützte POST-Aktion implementieren: Supabase-Abmeldung anstoßen und lokale Session einschließlich Cookie entfernen.
- [ ] Eine verständliche eigene Inaktivitätsgrenze festlegen. Logout macht bereits ausgestellte Access Tokens nicht zwangsläufig sofort ungültig; ihre Restlaufzeit beachten.

Lernziel: Anmeldung, Identitätsprüfung, Session-Lebensdauer und Abmeldung auseinanderhalten.

## Schritt 4: PostgreSQL-Profiltabelle und RLS

- [ ] Eine SQL-Datei für `public.profiles` erstellen: `id` als UUID-Primärschlüssel mit Verweis auf `auth.users(id)`, `display_name` als Text, `created_at` als Zeitstempel.
- [ ] RLS aktivieren und erforderliche Tabellenrechte ausdrücklich setzen.
- [ ] Regeln für SELECT, INSERT und UPDATE definieren: Die Benutzer-ID aus dem geprüften Token muss zur Profil-ID passen. Bei UPDATE auch den resultierenden Datensatz mit `WITH CHECK` absichern.
- [ ] Profil beim ersten angemeldeten Zugriff mit der bestätigten Benutzer-ID anlegen; keine vom Formular frei vorgegebene Benutzer-ID übernehmen.
- [ ] Profil über die Supabase-Daten-API lesen und bearbeiten. PHP verwendet dafür Publishable Key und Access Token des Benutzers.
- [ ] Die SQL-Datei als reproduzierbare Beschreibung des Datenbankschemas im Projekt speichern.

Für diese Funktionen ist kein Secret-Key und kein alter `service_role`-Key erforderlich. Diese privilegierten Schlüssel umgehen RLS und werden nicht für normale Profilzugriffe eingesetzt.

Lernziel: Fremdschlüssel, API-Zugriff, Tabellenrechte und zeilenbezogene Berechtigungen verstehen.

## Schritt 5: Registrierung und E-Mail-Abläufe ergänzen

- [ ] Registrierung als eigene Ausbaustufe hinzufügen.
- [ ] Site URL und erlaubte Redirect-URLs auf die tatsächliche Testwebsite einstellen.
- [ ] E-Mail-Bestätigung mit einem für die PHP-Anwendung passenden serverseitigen Callback-Verfahren umsetzen.
- [ ] Passwort-Zurücksetzen einschließlich Rückkehr zur Website implementieren und testen.
- [ ] Für E-Mails an beliebige Testadressen einen eigenen SMTP-Versand in Supabase einrichten. Einen vorhandenen World4You-Mailaccount nur verwenden, wenn dessen SMTP-Zugang und Versandvorgaben passen.

Der eingebaute Supabase-Mailversand ist eingeschränkt und liefert standardmäßig nur an vorab zugelassene Teamadressen. Deshalb zunächst mit im Dashboard angelegten, bestätigten Testbenutzern lernen. Den späteren Registrierungsablauf einschließlich E-Mail-Zustellung separat prüfen.

Lernziel: Registrierung, Bestätigung, Login und Passwortwiederherstellung als getrennte Abläufe verstehen.

## Geplante Projektstruktur

```text
plan.md
.gitignore
sql/
  001_profiles.sql
app/
  bootstrap.php
  supabase.php
  auth.php
  csrf.php
config/
  config.example.php
  config.local.php       # lokale Werte, nicht versionieren
public/                 # nur dieser Ordner ist öffentlich
  index.php
  login.php
  profile.php
  logout.php
  assets/
```

Die endgültige Ablage von `app` und `config` hängt vom zugänglichen Verzeichnisaufbau bei World4You ab. `ftp.txt`, `config.local.php`, temporäre Diagnosedateien und Logs in `.gitignore` berücksichtigen. Für Uploads ausschließlich die vorgesehenen Anwendungsdateien auswählen; nicht den gesamten Projektordner hochladen.

## Schritt 6: Bereitstellung und Abnahme

- [ ] Verbindung und Zielpfad anhand der lokalen `ftp.txt` prüfen; wenn verfügbar verschlüsselt per FTPS oder SFTP übertragen.
- [ ] Website in einem eigenen Testverzeichnis bereitstellen und HTTPS-Aufruf prüfen.
- [ ] Richtige Anmeldung funktioniert, falsches Passwort wird mit allgemeiner Fehlermeldung abgewiesen.
- [ ] Geschützte Seiten sind ohne Anmeldung auch über ihre direkte URL nicht erreichbar.
- [ ] Ein erneuter Seitenaufruf erhält die Anmeldung; Ablauf und Token-Erneuerung funktionieren.
- [ ] Benutzer A kann sein Profil lesen und ändern. Benutzer B kann weder über die Oberfläche noch über manipulierte API-Anfragen auf das Profil von A zugreifen.
- [ ] Auch ein Zugriff ohne Benutzer-Token erhält keine privaten Profile.
- [ ] Logout, abgelaufene Session und ungültiges Refresh Token führen zuverlässig zur erneuten Anmeldung.
- [ ] CSRF-Schutz und HTML-Escaping anhand konkreter Fehlversuche prüfen.
- [ ] Konfiguration, Zugangsdaten und Logs sind über HTTP nicht abrufbar; temporäre Testskripte entfernen.

Nach jeder Etappe kurz festhalten: Was wurde gebaut? Welche Anfrage läuft wohin? Welche Daten liegen im Browser, auf dem Webspace und bei Supabase? Wie wurde der Schritt überprüft?

## Nächster konkreter Schritt

Als Nächstes das PHP-Grundgerüst mit lokaler Konfiguration und einem begrenzten HTTPS-Verbindungstest vom World4You-Webspace aus bauen. Danach einen bestätigten Testbenutzer im Dashboard anlegen und den Login umsetzen. Das Supabase-Projekt ist bereits angelegt und vom Entwicklungsrechner erreichbar.

## Quellen

- [Geprüfte PHP-Konfiguration des Webspace](https://meuse24.info/phpinfo.php)
- [Supabase: Preise und Free-Tarif](https://supabase.com/pricing)
- [Supabase: Data REST API](https://supabase.com/docs/guides/api)
- [Supabase: API-Schlüssel](https://supabase.com/docs/guides/getting-started/api-keys)
- [Supabase: Passwortbasierte Anmeldung](https://supabase.com/docs/guides/auth/passwords)
- [Supabase: Sessions](https://supabase.com/docs/guides/auth/sessions)
- [Supabase: Eigene Benutzerdaten](https://supabase.com/docs/guides/auth/managing-user-data)
- [Supabase: Row Level Security](https://supabase.com/docs/guides/database/postgres/row-level-security)
- [Supabase: SMTP und Einschränkungen des eingebauten Mailversands](https://supabase.com/docs/guides/auth/auth-smtp)
