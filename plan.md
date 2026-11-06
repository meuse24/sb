# Lernprojekt: Eigene PHP-Anmeldung mit PostgreSQL bei Supabase

Stand: 05.11.2026

## Entscheidung und Ziel

Die Anwendung erhält eine eigene `users`-Tabelle mit Passwort-Hashes, wie beim bisherigen MariaDB-Ansatz des Benutzers. PHP übernimmt Passwortprüfung, Anmeldung und Sessions. Supabase wird als PostgreSQL-Anbieter mit HTTPS-Datenzugriff genutzt. Supabase Auth ist nicht mehr Bestandteil der Anwendung.

Die Website bleibt bei World4You unter https://meuse24.info/supabase-test/public/. Es ist kein Wechsel zu Render beauftragt. Die Unterhaltung über Render diente dem Vergleich der Architekturen.

Umgesetzt sind Anmeldung, geschützter Bereich, Abmeldung, bearbeitbarer Anzeigename, Passwortänderung und Registrierung. Zusätzlich ist jetzt eine Admin-Verwaltung aller Benutzer mit CRUD beauftragt. Auf ausdrücklichen Benutzerwunsch ist die E-Mail-Bestätigung vorläufig deaktiviert. Der vorbereitete Mailablauf bleibt optional; SMTP-Einrichtung und Passwort-Zurücksetzen folgen separat.

## Aktueller Stand

- Admin-Oberfläche auf World4You per FTPS bereitgestellt und dort über HTTPS vollständig abgenommen: anonyme und normale Benutzer gesperrt, CRUD, Suche/Seitenwechsel, Passwort-Neusetzen, Rollen-/Session-Widerruf, Selbstschutz, CSRF und veraltete Formulare. Temporäre Testkonten wurden entfernt. Auch die bestehenden Auth-, Profil- und Passworttests bestehen weiterhin.
- Der vorhandene Erstzugang aus `config/first-login.local.txt` wurde nach Prüfung der bisherigen Zugangsdaten zum ersten Admin gemacht. Passwort und E-Mail blieben unverändert; `auth_version` wurde zur Abmeldung alter Sitzungen erhöht. Eine erneute Anmeldung als Admin samt Zugriff auf die reale Verwaltung wurde bestätigt.
- Admin-CRUD implementiert: Liste mit Suche/Seitenwechsel, Anlage, Bearbeitung von E-Mail/Anzeigename/Rolle/Status, optionales Passwort-Neusetzen und endgültiges Löschen nach Bestätigung. `is_admin` ist standardmäßig `false`; normale Registrierungen und Profilformulare können keine Admin-Rechte erzeugen.
- Migration `004_admin.sql` ist angewandt. PHP prüft die frisch geladene Rolle bei jedem Admin-Aufruf. Interne Datenbankfunktionen prüfen zusätzlich aktive Actor-ID und Session-Version; Browser-Rollen erhalten kein Ausführungsrecht. Gegenläufige Admin-Änderungen werden in einer Transaktionssperre serialisiert. Eigene Deaktivierung, Herabstufung, Löschung und Passwort-Reset über die Admin-Seite sind gesperrt.
- Datenbanktests und vollständige Admin-Integration gegen die echte Daten-API bestehen: Rechteentzug, CRUD, Passwort-Reset, aktive/inaktive Konten, kein Hash in Antworten, CSRF, Such-/Seitenfunktionen, Konflikterkennung, öffentliche Registrierung ohne Rollenerhöhung und zwei gleichzeitige gegenseitige Herabstufungen mit nur einem Gewinner. Temporäre Testkonten wurden wieder entfernt.
- Registrierung ohne E-Mail-Bestätigung ist die gewählte aktuelle Variante. `registration_require_email_confirmation=false` erlaubt neue Konten mit E-Mail, Anzeigename, Passwort und Wiederholung; anschließend ist die Anmeldung sofort möglich. Es wird keine Mail verschickt und kein SMTP-Zugang benötigt. Doppelte E-Mail-Adressen dürfen bestehende Konten nicht überschreiben.
- Diese Variante ist auf World4You bereitgestellt und über HTTPS geprüft: CSRF, Passwortgrenzen und Wiederholung, ignorierte frei übergebene Kontofelder, nicht bestätigte E-Mail-Kennzeichnung, Duplikatsperre und sofortiger Login. Der optionale Bestätigungsendpunkt leitet bei deaktivierter Bestätigung zur Registrierung weiter. Das temporäre Testkonto wurde entfernt; der bestehende Erstzugang blieb unverändert.
- Die Integrationstests gegen die echte Daten-API bestehen für beide Varianten, einschließlich doppelter/gleichzeitiger Registrierung, persistenter Limits, optionalem Bestätigungsablauf, Replay-/Ablaufschutz und simuliertem Versandfehler. Eine echte E-Mail wurde nicht versandt. Die bestehenden Auth-, Profil- und Passworttests bestehen weiterhin.
- Migrationen 002 und 003 wurden mit zertifikatsgeprüftem Zugang auf PostgreSQL angewandt. `registration_requests` und die Registrierungsfunktionen sind für Browser-Rollen gesperrt. `users.email_confirmed_at` unterscheidet tatsächlich bestätigte Adressen von Sofortregistrierungen; vorhandene Konten wurden nicht pauschal als bestätigt markiert.
- Der optionale Bestätigungsablauf ist vorbereitet: Token nur gehasht speichern, 30 Minuten Laufzeit, 60 Sekunden Versandpause, Ersetzung alter Links, atomare Einmalverwendung und Passwortwahl erst durch den Empfänger nach dem Link. Der Fragment-/POST-Übergang hält rohe Tokens aus Server-Zugriffs-URLs heraus. Bei deaktivierter Bestätigung leitet `confirm.php` zur Registrierung weiter.
- PHPMailer 7.1.1 ist mit Composer-Lockdatei vorbereitet. Der SMTP-Adapter wurde gegen einen lokalen TLS-SMTP-Testserver geprüft, einschließlich Zertifikatsablehnung; dabei wurde keine externe E-Mail versandt. Eine echte SMTP-Konfiguration ist wegen der ausdrücklich deaktivierten Bestätigung derzeit nicht erforderlich. `config/mail.local.php` bleibt eine ignorierte lokale Vorlage.
- Passwortänderung unter `password.php` umgesetzt: bisheriges Passwort prüfen, neues Passwort wiederholen und serverseitig mindestens 12 Unicode-Zeichen bei höchstens 72 UTF-8-Bytes verlangen. Passwort-Hash und erhöhte `auth_version` werden atomar gespeichert, gebunden an aktive Benutzer-ID, bisherige Version und bisherigen Hash. Dafür war keine Datenbankmigration nötig.
- Die aktuelle Sitzung erhält nach Passwortänderung eine neue Session-ID und ein neues CSRF-Token; ihre ursprüngliche maximale Laufzeit bleibt erhalten. Andere Sitzungen werden beim nächsten geschützten Aufruf abgewiesen. Die Versuchsbegrenzung bleibt auch nach erneutem Login bestehen: eigener Kontozähler mit 10 Versuchen pro 15 Minuten, gemeinsam genutztes IP-Limit von 50 Versuchen.
- Integrationstests gegen die echte Daten-API bestehen für falsches/altes/neues Passwort, Wiederholung und Unicode-/Byte-Grenzen, CSRF, fremde Ziel-IDs, Session-Wechsel und Widerruf, Sperren für veraltete/deaktivierte Konten sowie zwei konkurrierende Änderungen mit genau einem Gewinner. Testkonten und zugehörige Zähler wurden entfernt.
- Auf World4You per FTPS bereitgestellt und über HTTPS abgenommen: Passwortprüfung, Eingabeprüfung/CSRF, Passwortwechsel, neue Session-ID und neues CSRF-Token, Widerruf einer zweiten Sitzung, Ablehnung des alten Passworts und Anmeldung mit dem neuen. Ein anderes Konto blieb unverändert und angemeldet. Temporäre Testkonten wurden entfernt; der vorhandene Erstzugang blieb unverändert.
- Etappe 3 umgesetzt: Der eigene Anzeigename lässt sich auf `profile.php` ändern oder leeren. Maximal 100 Unicode-Zeichen, CSRF-Schutz und HTML-Escaping. PATCH-Ziel, Kontostatus und Session-Version werden serverseitig festgelegt; frei übergebene Benutzer-IDs oder weitere Kontofelder werden nicht übernommen. Der Integrationstest gegen die echte Daten-API mit zwei temporären Konten besteht, einschließlich Manipulationen in beide Richtungen und Prüfung auf konkurrierenden Session-Widerruf. Testkonten wurden entfernt.
- Die Profiländerung ist auf World4You bereitgestellt. Der dortige HTTPS-Test mit zwei temporären Konten besteht für Speichern/Leeren, CSRF, Längenprüfung, HTML-Escaping, anonymen Zugriff und manipulierte Ziel-IDs in beide Richtungen. Zusätzliche übergebene Kontofelder bleiben unverändert. Auch diese Testkonten wurden anschließend entfernt; der bestehende Erstzugang wurde nicht verändert.
- Der Code ist von Supabase Auth auf eigene Passwortprüfung in PHP umgestellt. Access-/Refresh-Tokens und Token-Erneuerung sind entfernt. Der neue Session-Cookie heißt `php_users`; die bisherigen Auth-Sessions werden nicht übernommen.
- `public.users`, `public.login_attempts` und `public.consume_login_attempt()` wurden mit `sql/001_users.sql` in der tatsächlichen PostgreSQL-Datenbank angelegt. Vorher war das Schema `public` leer. Bestehende Tabellen im Schema `auth` wurden nicht verändert.
- Der Benutzer hat den benötigten Secret-Key lokal in `config/config.local.php` hinterlegt. Schlüssel und Passwörter werden weder im Plan noch im Repository gespeichert.
- Datenbanktests auf PostgreSQL 17.11 bestanden: Backend-Rechte, Sperren für `anon`/`authenticated`, RLS, eindeutige und normalisierte E-Mail-Adressen, E-Mail-/IP-Begrenzung und deren Ablauf. Alle Testdaten wurden zurückgerollt.
- Der Integrationstest mit PHP 8.5.12 gegen die echte Supabase-Daten-API bestand: Benutzeranlage per CLI, Passwortprüfung, Hash-Erneuerung, CSRF, Session-Wechsel, Cookie-Attribute, Escaping, Ablauf, Kontodeaktivierung, Session-Widerruf, Logout bei Backendausfall und Limits trotz Cookie-Löschung. Das zufällig angelegte Testkonto und seine Test-Buckets wurden entfernt.
- Die HTTPS-Version wurde per FTPS auf World4You bereitgestellt und mit einem eigenen Benutzer erfolgreich abgenommen: richtiger/falscher Login, Session-Erhalt und -Wechsel, Cookie-Attribute, CSRF, Wiederholungsbegrenzung, direkter Zugriffsschutz, Logout und Datenbanktest. Direkter HTTP-Zugriff auf Anwendungshilfen und Konfiguration liefert weiterhin 403. CLI und Tests sind nicht hochgeladen.
- Ein erster nutzbarer Testbenutzer wurde in `public.users` angelegt. Seine zufällig erzeugten Zugangsdaten stehen ausschließlich lokal in `config/first-login.local.txt`; die Datei wird ignoriert und wurde nicht hochgeladen. `app/supabase.php` ist lokal und auf dem Webspace entfernt.
- Projektordner aufgeräumt: benötigte FTP- und Datenbankzugangsdaten liegen jetzt in `config/ftp.local.txt` und `config/database.local.txt`. Die früheren Dateien im Projektstamm sind entfernt; überholte Publishable-Key- und CLI-Notizen wurden verworfen. Alle `config/*.local.*` bleiben von Git ausgeschlossen. Anwendung, SQL, CLI-Werkzeug und Tests sind erhalten.
- Git: Branch `main`, Remote `origin` unter https://github.com/meuse24/sb.git. Anwendungscode, SQL und Dokumentation werden versioniert; Zugangsdaten bleiben lokal.

## Hosting und gewählter Zugang

Die erneute Prüfung von https://meuse24.info/phpinfo.php zeigt PDO-Treiber `mysql, sqlite`. `pdo_pgsql` ist auf dem Webspace derzeit nicht vorhanden. Es lässt sich dort nicht durch Anwendungscode aktivieren; Installation oder Freischaltung liegen beim Hostinganbieter. Der Benutzer hat ausdrücklich **HTTPS mit eigener users-Tabelle** gewählt.

Vorhanden und praktisch geprüft sind PHP 8.4, cURL, Sessions und ausgehendes HTTPS zu Supabase. Der neue Datenbankzugriff nutzt cURL zur PostgREST-Daten-API mit Zertifikatsprüfung, Zeitlimits und abgeschirmten Fehlermeldungen. Weder ein PostgreSQL-PDO-Treiber noch ein direkter Datenbankport sind dafür auf dem Webspace nötig.

Vom Entwicklungsrechner war zusätzlich ein direkter PostgreSQL-Zugang mit den vorhandenen lokalen Datenbankdaten und `sslmode=verify-full` möglich. Dieser wurde ausschließlich für Schemaeinrichtung und Datenbanktests verwendet. Die Webanwendung nutzt HTTPS.

`app/` und `config/` liegen beim vorhandenen Hostingaufbau ebenfalls im Webspace. Geprüfte `.htaccess`-Sperren schützen sie vor HTTP-Zugriffen. FTPS erfolgt mit Zertifikatsprüfung. Andere Website-Dateien werden nicht überschrieben. Eine öffentliche phpinfo-Datei sollte nach Abschluss der Diagnose entfernt oder geschützt werden.

## Architektur und Datenfluss

```text
Browser
  | HTTPS: E-Mail/Passwort-Formular, CSRF-Token, Session-Cookie
  v
PHP bei World4You
  +-- Eingaben prüfen und E-Mail normalisieren
  +-- Anmeldeversuche begrenzen
  +-- password_verify() / password_needs_rehash()
  +-- Session erstellen, prüfen und beenden
  |
  | HTTPS: serverseitiger Secret-Key
  v
Supabase-Daten-API / PostgREST
  |
  v
PostgreSQL
  +-- public.users
  +-- public.login_attempts
  +-- public.consume_login_attempt()
```

- Die eigene UUID bleibt unabhängig von Supabase Auth. Weitere Anwendungstabellen werden auf `public.users.id` verweisen, nicht auf `auth.users.id`.
- E-Mail und Passwort gelangen über HTTPS an PHP. Das Passwort wird nur während der Prüfung verarbeitet, nicht protokolliert oder in der Session gespeichert.
- Der Passwort-Hash wird ausschließlich im PHP-Backend aus der Datenbank gelesen. Der Browser erhält weder Hash noch Secret-Key.
- Die PHP-Session enthält nur eigene Benutzer-ID, `auth_version` und Zeitstempel. Der Browser besitzt nur die zufällige Session-ID als Secure-/HttpOnly-/SameSite=Lax-Cookie.
- Logout beendet die lokale Session und entfernt das Cookie; ein Supabase-Logout ist nicht mehr erforderlich.
- Supabase-Auth-Konten und unsere eigenen Anwendungskonten sind getrennt. Ein im Dashboard unter Authentication → Users angelegter Benutzer kann sich damit nicht automatisch bei unserer Website anmelden.

## Datenmodell und Zugriffsschutz

`public.users`:

| Feld | Zweck |
| --- | --- |
| `id` | Eigene UUID, Primärschlüssel |
| `email` | Kleingeschriebene, eindeutige E-Mail-Adresse, maximal 254 Zeichen |
| `password_hash` | PHP-Passwort-Hash, kein Klartext |
| `display_name` | Anzeigename, maximal 100 Zeichen |
| `is_active` | Konto deaktivieren, ohne Datensätze zu löschen |
| `is_admin` | Serverseitig geprüfte Berechtigung zur Verwaltung aller Konten; standardmäßig `false` |
| `auth_version` | Bestehende Sessions nach Passwortänderung oder Widerruf ungültig machen |
| `created_at` | Erstellungszeitpunkt mit Zeitzone |
| `email_confirmed_at` | Tatsächlicher Bestätigungszeitpunkt; `NULL` bei aktueller Sofortregistrierung oder manueller Anlage |

Die Tabellen haben RLS aktiviert. `anon` und `authenticated` besitzen keine Tabellenrechte und keine passenden Policies. Die interne RPC-Funktion ist für diese Rollen ebenfalls gesperrt. Nur das vertrauenswürdige PHP-Backend greift mit dem Secret-Key als `service_role` darauf zu. Dieser Schlüssel umgeht RLS; deshalb prüft PHP den angemeldeten Benutzer und dessen Berechtigungen selbst.

Die frühere Planung mit `auth.uid()`-Policies passt nicht mehr: Eine reine PHP-Session stellt keinen Supabase-Benutzer-JWT bereit. Wir dürfen die eigenen Tabellen deshalb keinesfalls mit einer allgemeinen Browser-Policy freigeben. Der Zugriffsschutz für Profiländerungen liegt im PHP-Backend: Benutzer-ID immer aus der geprüften Session, niemals frei aus dem Formular übernehmen.

Die Anmeldebegrenzung speichert SHA-256-Buckets für normalisierte E-Mail und IP. Pro 15-Minuten-Fenster sind 10 Versuche pro E-Mail und 50 pro IP erlaubt, einschließlich erfolgreicher Versuche. Die PostgreSQL-Funktion aktualisiert beide Zähler atomar; Cookie-Löschung setzt sie nicht zurück. Buckets älter als 24 Stunden werden bei späteren Versuchen gelöscht. Zusätzlich gilt eine Pause von drei Sekunden pro PHP-Session.

## Etappe 1: Schema und HTTPS-Zugriff

- [x] Reproduzierbare Migration `sql/001_users.sql` erstellen und anwenden.
- [x] Eigene UUIDs und Passwort-Hashes ohne Abhängigkeit von `auth.users` vorsehen.
- [x] Tabellenrechte und RLS gegen Browserzugriffe absichern.
- [x] Secret-Key ausschließlich lokal und im geschützten Backend konfigurieren.
- [x] HTTPS-Adapter hinter einer eigenen `UserStore`-Schnittstelle kapseln.
- [x] Reale Datenbankrechte und Limits mit zurückgerollten Testdaten prüfen.

Lernziel: Datenbank, Datentransport und Anmeldeverfahren getrennt verstehen.

## Etappe 2: Eigene Anmeldung und erster Benutzer

- [x] Benutzeranlage als lokales CLI-Werkzeug `bin/create-user.php` umsetzen.
- [x] Hashes mit `password_hash(PASSWORD_DEFAULT)` erzeugen; Passwörter nicht als Kommandozeilenargument übergeben.
- [x] Bei neuer Benutzeranlage mindestens 12 Unicode-Zeichen und höchstens 72 UTF-8-Bytes verlangen; bcrypt darf nichts still abschneiden. Dieselben Regeln gelten für neue Passwörter bei einer Passwortänderung.
- [x] Login mit `password_verify()` prüfen und veraltete Hashes nach erfolgreicher Prüfung erneuern.
- [x] Fehlermeldung bei unbekannter E-Mail, falschem Passwort oder deaktiviertem Konto allgemein halten.
- [x] CSRF, Eingabeprüfung, HTML-Escaping und persistente Begrenzung der Anmeldeversuche umsetzen.
- [x] Session-ID und CSRF-Token beim Login erneuern.
- [x] Geschützte Seiten gegen direkte Aufrufe absichern und das Konto bei jedem Zugriff erneut laden.
- [x] Sessions nach 30 Minuten Inaktivität oder spätestens acht Stunden beenden.
- [x] Deaktivierte/gelöschte Konten und geänderte `auth_version` zurückweisen.
- [x] Logout als CSRF-geschützte POST-Aktion auch ohne verfügbares Backend ermöglichen.
- [x] Vollständigen Ablauf mit einem echten, anschließend entfernten Testkonto über HTTPS prüfen.
- [x] Online-Version auf dem World4You-Webspace abschließend abnehmen und ersten nutzbaren Testzugang bereitstellen.

Lernziel: Passwort-Hash, Passwortprüfung, Benutzeridentität und Session-Lebensdauer nachvollziehen.

## Etappe 3: Eigenes Profil bearbeiten

- [x] Anzeigenamen auf der geschützten Seite bearbeiten können.
- [x] Update immer auf die Benutzer-ID aus der geprüften Session begrenzen.
- [x] Formular mit CSRF schützen und Länge serverseitig prüfen.
- [x] Mit zwei echten Benutzern nachweisen, dass A weder Lesen noch Ändern von B über manipulierte Anfragen erzwingen kann.
- [x] Anzeigenamen direkt in `public.users` speichern; eine zusätzliche Profiltabelle ist dafür nicht nötig. Weitere Felder bleiben auf spätere Anforderungen begrenzt.

## Etappe 4: Passwortänderung, Registrierung und E-Mail

- [x] Passwortänderung mit Prüfung des bisherigen Passworts ergänzen.
- [x] Nach einer Passwortänderung `auth_version` erhöhen und den gewünschten Session-Widerruf prüfen.
- [x] Registrierung als eigene Ausbaustufe mit Spam-/Missbrauchsbegrenzung entwickeln; aktuell ohne Bestätigungsmail.
- [x] Optionale E-Mail-Bestätigung mit kurzlebigen Einmal-Tokens vorbereiten; Tokens nur gehasht speichern. Auf Benutzerwunsch deaktiviert.
- [ ] Passwort-Zurücksetzen mit Einmal-Token, Ablaufzeit und neutraler Antwort unabhängig vom Vorhandensein der E-Mail implementieren.
- [ ] Bei späterer Aktivierung eigenen SMTP-Versand konfigurieren und echte Zustellung testen. Der Supabase-Auth-Mailversand übernimmt diese eigenen Abläufe nicht. Derzeit bewusst nicht erforderlich.

## Etappe 5: Admin-CRUD

- [x] Admin-Rolle mit sicherem Standardwert und Prüfung bei jedem Zugriff ergänzen.
- [x] Liste mit Suche und Seitenwechsel sowie Lesen einzelner Konten anbieten.
- [x] Benutzer anlegen, bearbeiten, Passwörter neu setzen und nach Bestätigung löschen können.
- [x] CSRF und Eingabeprüfung für alle schreibenden Formulare sowie Konfliktprüfung mit `auth_version` umsetzen.
- [x] Rollenentzug und Deaktivierung beim nächsten Zugriff durchsetzen; Admin-Änderungen widerrufen bestehende Sitzungen.
- [x] Eigene Löschung/Deaktivierung/Herabstufung verhindern und gleichzeitigen gegenseitigen Rollenentzug testen.
- [x] Normale Registrierung und Profilbearbeitung gegen Rollenerhöhung absichern.
- [x] Bestehenden lokalen Erstzugang gezielt als ersten Admin freischalten, bereitstellen und auf World4You abnehmen.

## Etappe 6: Anbieterwechsel vorbereiten

- [x] Benutzer-ID und Anmeldelogik unabhängig von Supabase Auth halten.
- [x] Datenzugriff in einem Adapter kapseln.
- [ ] Bei einem tatsächlichen Wechsel einen PDO/PostgreSQL-Adapter implementieren und dafür einen eingeschränkten Backend-Datenbankbenutzer einrichten.
- [ ] Datenexport und Wiederherstellung auf einer zweiten PostgreSQL-Instanz testen, einschließlich UUIDs und Passwort-Hashes.
- [ ] Anbieterbezogene Grants, RLS und die Bereitstellung der Rate-Limit-Funktion anpassen.
- [ ] HTTPS-Adressen, Konfiguration und SMTP bei einem Hostingwechsel prüfen; aktive PHP-Sessions dürfen dabei beendet werden.

Ein Wechsel zu einem anderen PostgreSQL-Anbieter benötigt voraussichtlich weniger Anpassungen als ein Wechsel zu MariaDB. Die eigene Benutzertabelle reduziert die Bindung an den Anmeldedienst, beseitigt aber nicht alle Unterschiede zwischen Datenbanken und Hostingangeboten.

## Projektstruktur

```text
app/
  bootstrap.php
  auth.php
  admin.php
  mail.php
  csrf.php
  user-store.php
  supabase-http-store.php
bin/
  create-user.php
config/
  .htaccess
  config.example.php
  mail.example.php
  mail.local.php            # ignoriert; vorbereitete SMTP-Konfiguration, derzeit nicht benötigt
  config.local.php          # ignoriert; Server-Schlüssel
  database.local.txt        # ignoriert; nur lokal, Datenbankzugang
  ftp.local.txt             # ignoriert; nur lokal, Bereitstellungszugang
  first-login.local.txt     # falls erzeugt: ignoriert; niemals hochladen
public/
  index.php
  login.php
  profile.php
  admin.php
  admin-user.php
  admin-delete.php
  logout.php
  password.php
  register.php
  confirm.php
  assets/style.css
  assets/confirmation.js
sql/
  001_users.sql
  002_registration.sql
  003_registration_without_confirmation.sql
  004_admin.sql
tests/
  auth-flow.mjs
  database-security.sql
  registration-flow.mjs
  registration-security.sql
  mail-transport.mjs
  admin-flow.mjs
  admin-security.sql
composer.json
composer.lock
```

## Bereitstellung und Abnahme

- [x] Eigenes Zielverzeichnis `supabase-test` und FTPS mit Zertifikatsprüfung verwenden.
- [x] Datenbankmigration vor dem Anwendungswechsel ausführen und prüfen.
- [x] Ausschließlich erforderliche Anwendungsdateien und geschützte Konfiguration hochladen.
- [x] Das ungenutzte frühere `app/supabase.php` nach dem Wechsel entfernen.
- [x] Über HTTPS korrekten/falschen Login, direkten Zugriffsschutz, Cookies, CSRF und Logout prüfen.
- [x] Zugriff auf `app/` und `config/` einschließlich lokaler Konfiguration weiterhin mit HTTP 403 prüfen.
- [x] Sicherstellen, dass SQL, CLI-Werkzeuge, Testhilfen und lokale Zugangsdaten nicht bereitgestellt wurden.

Keine Passwörter, Datenbankverbindungsstrings oder Secret-Keys in Git, Logs oder diesen Plan aufnehmen. Alle Dateien nach dem Muster `config/*.local.*` bleiben ignoriert. Beim Upload aus `config/` ausschließlich `.htaccess` und die notwendige `config.local.php` auswählen; die übrigen lokalen Zugangsdaten niemals hochladen. `.gitignore` schützt nicht vor FTP-Uploads.

## Nächster konkreter Schritt

Nach erneuter Anmeldung mit dem bisherigen Erstzugang die Benutzerverwaltung unter https://meuse24.info/supabase-test/public/admin.php öffnen. Dort stehen vollständiges CRUD, Rollenvergabe und administratives Passwort-Neusetzen bereit. SMTP und der optionale Bestätigungsablauf bleiben deaktiviert. Ein öffentlicher „Passwort vergessen“-Ablauf ist noch offen und benötigt einen gesondert eingerichteten Versandweg; davor den gewünschten Mailversand mit dem Benutzer klären.

## Quellen

- [PHP: password_hash](https://www.php.net/manual/en/function.password-hash.php)
- [PHP: password_verify](https://www.php.net/manual/en/function.password-verify.php)
- [PHP: password_needs_rehash](https://www.php.net/manual/en/function.password-needs-rehash.php)
- [Supabase: Daten-API](https://supabase.com/docs/guides/api)
- [Supabase: API-Schlüssel](https://supabase.com/docs/guides/getting-started/api-keys)
- [Supabase: Row Level Security](https://supabase.com/docs/guides/database/postgres/row-level-security)
- [Supabase: PostgreSQL-Verbindungen](https://supabase.com/docs/guides/database/connecting-to-postgres)
