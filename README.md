# PHP und PostgreSQL lernen

Eine kleine PHP-Testwebsite für World4You mit eigener Benutzerverwaltung. Supabase stellt PostgreSQL und die HTTPS-Daten-API bereit. PHP prüft Passwörter mit `password_verify()` und verwaltet die Anmeldung über Sessions. Supabase Auth wird nicht mehr verwendet.

## Aufbau

```text
Browser --HTTPS/Formular--> PHP bei World4You
                               | password_verify() + PHP-Session
                               | HTTPS/cURL + serverseitiger Secret-Key
                               v
                         Supabase-Daten-API
                               |
                               v
                         PostgreSQL: public.users
```

`public.users` enthält eine eigene UUID, normalisierte E-Mail-Adresse, Passwort-Hash, Anzeigename, Kontostatus und Session-Version. Es gibt keinen Verweis auf `auth.users`. Bestehende Supabase-Auth-Konten bleiben unverändert und sind keine Konten für den neuen Login.

Die PHP-Anmeldelogik hängt nur von `UserStore` ab. Der aktuelle Adapter `SupabaseHttpUserStore` spricht die Daten-API an. Ein späterer Wechsel zu direktem PostgreSQL/PDO erfordert einen anderen Adapter. Die Tabellen und Passwort-Hashes können übernommen werden; Supabase-Grants, RLS und die API-spezifische Bereitstellung müssen beim Umzug angepasst werden. Bei einem Wechsel zu MariaDB muss auch das PostgreSQL-Schema angepasst werden.

## Einrichten

1. Die SQL-Migrationen `001_users.sql`, `002_registration.sql`, `003_registration_without_confirmation.sql` und `004_admin.sql` in dieser Reihenfolge jeweils einmalig im Supabase SQL Editor ausführen. Bei einem bestehenden Projekt nur die noch nicht angewandten Migrationen ausführen. Sie überschreiben keine vorhandenen Konten.
2. `config/config.example.php` nach `config/config.local.php` kopieren. Projekt-URL und einen **Secret-Key** (`sb_secret_...`) aus Settings → API Keys eintragen. Kein Publishable Key: Dieser erhält bewusst keinen Zugriff auf unsere Benutzer und Passwort-Hashes.
3. Einen Benutzer über die Registrierung oder lokal mit dem unten beschriebenen CLI-Werkzeug anlegen.
4. Anwendung und lokale Serverkonfiguration gezielt bereitstellen; danach Verbindungstest und Anmeldung prüfen.

Die lokale Konfiguration ist von Git ausgeschlossen. Secret-Keys gehören ausschließlich ins Backend, niemals in HTML, JavaScript, Logs oder die Versionsverwaltung. Der Secret-Key hat erhöhte Rechte und umgeht RLS. Deshalb kontrolliert PHP jeden Benutzerzugriff selbst. Die SQL-Datei entzieht `anon` und `authenticated` alle Tabellenrechte und das Recht, die Anmeldebegrenzung aufzurufen. Für diese Rollen existieren keine RLS-Policies.

## Ersten Benutzer anlegen

Die PHP-CLI benötigt cURL und einen gültigen CA-Zertifikatsspeicher. Mit der lokalen Konfiguration ausführen:

```text
php bin/create-user.php deine-adresse@example.com "Dein Name"
```

Anschließend das gewünschte Passwort über STDIN eingeben und Enter drücken. Das Terminal kann die Eingabe anzeigen. Das Passwort nicht als Kommandozeilenargument oder als wörtlichen Befehl in die Shell-History schreiben. Alternativ kann ein Passwortmanager oder ein lokaler Prozess das Passwort über eine STDIN-Pipe liefern.

Das Werkzeug akzeptiert mindestens 12 Unicode-Zeichen und höchstens 72 UTF-8-Bytes und speichert nur den mit `password_hash(PASSWORD_DEFAULT)` erzeugten Hash. Umlaute und Emojis belegen mehrere Bytes. Die Obergrenze verhindert das stille Abschneiden langer Passwörter durch bcrypt. Ein eigener Salt wird von PHP automatisch erzeugt. Doppelte E-Mail-Adressen werden abgewiesen; E-Mail-Adressen werden für dieses Lernprojekt vollständig kleingeschrieben.

Danach unter [Anmelden](https://meuse24.info/supabase-test/public/login.php) einloggen. Benutzer werden in **Table Editor → public → users** sichtbar. Authentication → Users gehört zum bisherigen Supabase-Auth-Verfahren und wird von unserer Anmeldung nicht verwendet.

Im geschützten Bereich lässt sich der eigene Anzeigename bearbeiten oder leeren. Er darf maximal 100 Unicode-Zeichen enthalten. Änderungen erfordern ein gültiges CSRF-Token; die Benutzer-ID stammt ausschließlich aus der geprüften Session. Das Speichern berücksichtigt auch Kontostatus und Session-Version, um einen gleichzeitig erfolgten Widerruf zu beachten. Andere Kontofelder können über dieses Formular nicht geändert werden.

Für den aktuellen Projektstand wurde bereits ein erster Testzugang angelegt. Seine Zugangsdaten stehen ausschließlich lokal in `config/first-login.local.txt`. Diese Datei niemals hochladen. Die neue Anmeldung wurde auf dem tatsächlichen World4You-Webspace über HTTPS erfolgreich geprüft.

## Verhalten der Anmeldung

- PHP prüft den gespeicherten Hash und aktualisiert ihn nach erfolgreichem Login, wenn `password_needs_rehash()` dies empfiehlt.
- Beim Login wechseln Session-ID und CSRF-Token. Im Browser liegt nur das Session-Cookie mit `Secure`, `HttpOnly` und `SameSite=Lax`.
- Die serverseitige Session enthält Benutzer-ID, Session-Version und Zeitstempel, keine Passwörter, Passwort-Hashes oder Supabase-Tokens.
- Vor jedem geschützten Seitenaufruf lädt PHP das aktuelle Konto. Gelöschte oder deaktivierte Konten sowie eine geänderte `auth_version` beenden die Anmeldung.
- Die Sitzung endet nach 30 Minuten ohne geschützten Seitenaufruf oder spätestens acht Stunden nach Anmeldung.
- Logout ist eine CSRF-geschützte POST-Aktion und löscht Session und Cookie. Er funktioniert auch bei einem Datenbankausfall.
- Eine Cookie-unabhängige Datenbankbegrenzung erlaubt pro 15-Minuten-Fenster 10 Versuche je E-Mail und 50 je IP, einschließlich erfolgreicher Versuche. Dazu kommt eine Pause von drei Sekunden je PHP-Sitzung. E-Mail/IP werden als SHA-256-Buckets gespeichert; alte Buckets werden bei späteren Anmeldeversuchen nach 24 Stunden bereinigt. Die Begrenzung wird atomar in PostgreSQL ausgeführt.

## Passwort ändern

Im Profil führt „Passwort ändern“ zu `password.php`. Das bisherige Passwort, ein anderes neues Passwort und dessen Wiederholung sind erforderlich. Die Seite prüft CSRF, Zeichen-/Byte-Grenzen und das bisherige Passwort. Passwortfelder werden nach einem Fehler nicht erneut befüllt. Für neue Passwörter gelten dieselben Regeln wie bei der Benutzeranlage.

Passwort-Hash und `auth_version` werden gemeinsam mit einem bedingten Update gespeichert. Es muss weiterhin dieselbe aktive Benutzer-ID, Session-Version und derselbe zuvor geprüfte Passwort-Hash vorliegen. So kann bei zwei gleichzeitigen Änderungen nur eine erfolgreich sein. Es ist keine zusätzliche Datenbankmigration erforderlich.

Nach Erfolg werden Session-ID und CSRF-Token erneuert. Die aktuelle Sitzung bleibt mit der neuen Version angemeldet; ihre ursprüngliche maximale Laufzeit verlängert sich nicht. Andere bestehende Sitzungen werden beim nächsten geschützten Zugriff abgewiesen. Bei fehlgeschlagener Session-Erneuerung wird lokal abgemeldet und zur Anmeldung mit dem neuen Passwort weitergeleitet.

Passwortprüfungen sind je Konto auf 10 Versuche pro 15-Minuten-Fenster begrenzt, unabhängig von Cookie oder PHP-Session. Der Kontozähler ist vom normalen Login getrennt, die IP-Grenze von 50 Versuchen wird gemeinsam verwendet. Falls ein Backendausfall die Bestätigung verhindert, kann die Datenbank die Änderung bereits gespeichert haben; die Seite weist dann auf eine erneute Anmeldung mit gegebenenfalls neuem Passwort hin.

Die lokale Datei `config/first-login.local.txt` wird bei einer selbst durchgeführten Passwortänderung nicht aktualisiert; sie enthält anschließend das bisherige Passwort.

Die Passwortänderung ist auf World4You bereitgestellt und mit temporären Konten über HTTPS geprüft, einschließlich Session-Wechsel, Widerruf einer zweiten Sitzung und Anmeldung mit dem neuen Passwort. Der vorhandene erste Testzugang wurde dabei nicht verändert.

## Admin und Benutzerverwaltung

Ein Admin erreicht die Verwaltung unter `admin.php` oder über „Benutzerverwaltung öffnen“ im Profil. Sie bietet Suche und Seiten mit je 25 Konten sowie vollständiges CRUD: Benutzer anlegen, Kontodaten lesen, E-Mail/Anzeigename/Rolle/Status ändern, Passwörter neu setzen und Konten löschen. Passwort-Hashes werden in der Verwaltung weder angezeigt noch an deren Browser ausgeliefert. Beim Bearbeiten bedeutet ein leeres Passwortfeld: bestehendes Passwort behalten.

`users.is_admin` ist standardmäßig `false`. Öffentliche Registrierung und normale Profilformulare können die Rolle nicht setzen. PHP lädt die Rolle bei jedem geschützten Zugriff neu. Zusätzlich prüfen die internen Datenbankfunktionen die aktive Admin-ID und deren Session-Version aus der PHP-Session; keine dieser Funktionen ist für Browser-Rollen freigegeben. Das Actor-Konto wird während der Aktion gegen gleichzeitigen Rechteentzug gesperrt. Gleichzeitige Admin-Änderungen werden mit einer [PostgreSQL-Transaktionssperre](https://www.postgresql.org/docs/17/explicit-locking.html) geordnet.

Jede administrative Kontoänderung erhöht `auth_version` und widerruft bestehende Sitzungen dieses Kontos, auch beim Neusetzen eines Passworts. Bei Änderung der E-Mail wird deren Bestätigungsstatus gelöscht. Gleichzeitige oder veraltete Formularänderungen werden abgewiesen. Wer das eigene Konto administrativ bearbeitet, meldet sich danach erneut an; das eigene Passwort wird weiterhin über die normale Passwortänderung mit Prüfung des bisherigen Passworts geändert.

Löschen erfordert eine eigene Bestätigungsseite und einen CSRF-geschützten POST. Deaktivieren ist die Alternative zum endgültigen Löschen. Das eigene Admin-Konto lässt sich hier weder löschen, deaktivieren noch herabstufen. Auch bei gleichzeitigem gegenseitigem Rollenentzug bleibt ein aktiver Admin erhalten. Ein gelöschter Benutzer darf sich bei offener Registrierung später neu registrieren; Deaktivierung erhält dagegen seine E-Mail-Belegung.

Der erste Admin wird gezielt durch den Datenbankbetreiber freigeschaltet, nicht durch eine öffentliche „erster Benutzer wird Admin“-Regel. Für eine spätere Wiederherstellung im SQL Editor mit einer eigenen, vertrauenswürdigen E-Mail-Adresse ausführen:

```sql
UPDATE public.users
SET is_admin = true, auth_version = auth_version + 1
WHERE email = 'deine-adresse@example.com' AND is_active;
```

Das erhöht keine anderen Konten und erfordert anschließend eine neue Anmeldung. Der vorhandene lokale Erstzugang wurde nach Prüfung seiner bisherigen Zugangsdaten als erster Admin freigeschaltet; sein Passwort wurde nicht geändert. Seine Zugangsdaten stehen weiterhin in `config/first-login.local.txt`. Nach erneuter Anmeldung erscheint im Profil der Link zur [Benutzerverwaltung](https://meuse24.info/supabase-test/public/admin.php).

Die Verwaltung wurde auf World4You über HTTPS mit temporären Admin- und normalen Konten vollständig geprüft, einschließlich CRUD, Rollenentzug, Passwort-Neusetzen, Selbstschutz und gleichzeitiger Herabstufung. Testkonten wurden entfernt; der reale Erstzugang wurde nur gezielt zum Admin gemacht.

## Registrierung

Unter [Registrieren](https://meuse24.info/supabase-test/public/register.php) können neue Benutzer E-Mail, optionalen Anzeigenamen sowie Passwort und Wiederholung angeben. Auf ausdrücklichen Wunsch ist die E-Mail-Bestätigung vorläufig deaktiviert: `registration_require_email_confirmation` ist in `config/config.local.php` auf `false` gesetzt. SMTP wird in diesem Modus nicht benötigt und es wird keine E-Mail verschickt. Nach erfolgreicher Registrierung ist die Anmeldung sofort möglich.

E-Mail-Adressen werden normalisiert; für Anzeigename und Passwort gelten dieselben Grenzen wie im Profil bzw. bei der Passwortänderung. CSRF und die dauerhafte Anmeldebegrenzung schützen das Formular. Pro E-Mail sind 10 Registrierungsversuche je 15 Minuten erlaubt; der gemeinsame IP-Zähler erlaubt 50 Versuche. Eine Datenbankfunktion legt Konten atomar an. Doppelte oder gleichzeitige Registrierungen überschreiben weder Passwort noch Anzeigename eines bestehenden Kontos. Eine bereits bestehende PHP-Anmeldung wird bei erfolgreicher Neuregistrierung beendet.

`users.email_confirmed_at` bleibt bei Sofortregistrierung und manuell angelegten Konten `NULL`; diese Adressen werden nicht fälschlich als bestätigt markiert. Die aktuelle Anmeldung erlaubt solche Konten ausdrücklich. Eine spätere Aktivierung der Bestätigung betrifft neue Registrierungen und erzwingt nicht automatisch eine Nachbestätigung vorhandener Konten. Passwort-Zurücksetzen ist noch nicht implementiert.

Die Sofortregistrierung ist auf World4You bereitgestellt und über HTTPS abgenommen: Registrierung ohne SMTP, Eingabeprüfung/CSRF, Schutz zusätzlicher Kontofelder, Duplikatsperre und unmittelbare Anmeldung. Das dafür erzeugte Testkonto wurde entfernt; bestehende Konten und Zugangsdaten blieben unverändert.

## Vorbereitete E-Mail-Bestätigung

Der optionale Ablauf ist implementiert und getestet, bleibt derzeit deaktiviert. Für eine spätere Aktivierung zuerst `composer install --no-dev --no-scripts` lokal ausführen, `app/vendor/` mit bereitstellen und `config/mail.example.php` nach `config/mail.local.php` kopieren. SMTP-Zugang, Absender und die feste öffentliche `base_url` lokal eintragen. PHPMailer ist über `composer.lock` festgelegt; auf dem Webspace selbst wird Composer nicht benötigt. Danach verschlüsselten Versand und Zustellung prüfen und erst dann `registration_require_email_confirmation` auf `true` setzen.

Bei aktivierter Bestätigung wird zunächst nur E-Mail und Anzeigename angefordert. Ein zufälliger Einmal-Token gilt 30 Minuten; `registration_requests` speichert ausschließlich dessen SHA-256-Hash. Ein erneuter Versand ist frühestens nach 60 Sekunden möglich. Ein neuer Link ersetzt den vorherigen. Bestehende Konten und kürzlich angeforderte Adressen erhalten dieselbe neutrale Antwort. Bei fehlgeschlagenem Versand wird der neue Antrag nach Möglichkeit entfernt, die öffentliche Antwort bleibt neutral; das Backend protokolliert nur eine allgemeine Fehlermeldung ohne Adresse oder Token.

Der Link nutzt ein URL-Fragment. Ein kleines Skript auf `confirm.php` entfernt es aus der Browseradresse und übergibt den Token per CSRF-geschütztem POST. JavaScript ist nur für diesen optionalen Bestätigungsschritt erforderlich. Der Empfänger wählt dann sein Passwort selbst. Erst der abschließende POST legt das Konto an und setzt `email_confirmed_at`; ein Mailscanner, der den Link nur öffnet, bestätigt damit kein Konto. Ablauf, Einmalverwendung und Schutz vor konkurrierenden Bestätigungen werden in PostgreSQL geprüft. Abgelaufene Anträge werden bei späteren Registrierungsanfragen bereinigt.

Der SMTP-Adapter erzwingt STARTTLS oder SMTPS samt Zertifikatsprüfung und gibt keine SMTP-Fehler oder Zugangsdaten an den Browser aus. Für ein World4You-Postfach nennt die [offizielle Anleitung](https://faq.world4you.com/en/articles/4617) `smtp.world4you.com`, Port 587 und die vollständige E-Mail-Adresse als Benutzername; das E-Mail-Passwort ist vom FTP-Passwort unabhängig. Der echte Versand und die Zustellung sind derzeit mangels SMTP-Konfiguration nicht abgenommen.

## Bereitstellen

Die Anwendung benötigt PHP ab 8.4, cURL, Sessions und HTTPS. Auf dem vorhandenen World4You-Webspace ist `pdo_pgsql` nicht erforderlich. `public/` ist das öffentliche Verzeichnis. `app/` und `config/` nach Möglichkeit außerhalb davon ablegen, mit unveränderter relativer Struktur.

Beim vorhandenen Aufbau liegen alle drei Ordner im Webspace unter `supabase-test/`. Die `.htaccess`-Dateien sperren HTTP-Zugriffe auf `app/` und `config/`; diese Sperren müssen vor einem Konfigurationsupload geprüft werden.

Nur die ausgewählten Dateien aus `app/`, `config/` und `public/` per FTPS mit Zertifikatsprüfung bereitstellen. Aus `config/` ausschließlich `.htaccess`, die benötigte `config.local.php` und bei späterer SMTP-Aktivierung `mail.local.php` hochladen. FTP-Zugang (`config/ftp.local.txt`), Datenbankzugang (`config/database.local.txt`), erster Login (`config/first-login.local.txt`), `.git/`, `bin/`, `tests/` und SQL-Dateien bleiben lokal. `.gitignore` verhindert keine versehentlichen FTP-Uploads. In der aktuell deaktivierten Mailvariante ist `app/vendor/` auf dem Webspace nicht erforderlich.

Der Cookie-Name `php_users` trennt die Anmeldung von der früheren Supabase-Auth-Version. Supabase-Auth-Benutzer und deren Passwort-Hashes werden nicht automatisch übernommen.

## Prüfen

`tests/database-security.sql` kann nach der Migration als Datenbankadministrator ausgeführt werden. Es prüft die realen Rechte von Browser- und Backend-Rollen, RLS, E-Mail-Eindeutigkeit sowie die Grenzen und den Ablauf der Anmeldeversuche. Alle Teständerungen werden zurückgerollt.

Der Integrationstest verwendet echte HTTPS-Aufrufe gegen das lokal konfigurierte Projekt. Er legt zwei zufällig benannte Testbenutzer an und entfernt sie und die verwendeten Rate-Limit-Buckets danach wieder. Er ist für ein eigenes Testprojekt gedacht; insbesondere darf parallel kein anderer Test denselben IP-Bucket verwenden.

```powershell
$env:AUTH_TEST_LIVE = '1'
node tests/auth-flow.mjs php
Remove-Item Env:\AUTH_TEST_LIVE
```

Node.js ab Version 22 und PHP ab 8.4 mit cURL sind nötig. Zusätzliche PHP-Optionen können nach dem PHP-Pfad übergeben werden, etwa die CA-Konfiguration. Der Test kopiert die Anwendung in ein temporäres Verzeichnis und startet dort einen PHP-Server. Nur der Testclient sendet dessen Secure-Cookie ausdrücklich über lokales HTTP; die Anwendung selbst bleibt auf HTTPS ausgelegt. Die temporäre Hilfsseite wird nie bereitgestellt.

Geprüft werden Benutzeranlage, Passwortprüfung und Rehash, Eingabeprüfung, CSRF, Cookie-Attribute, Session-ID-Wechsel, HTML-Escaping, direkte Zugriffssperre, Inaktivität und absolute Laufzeit, alte Sessions, Kontodeaktivierung, Session-Version, Abmeldung bei Backendausfall sowie persistente Begrenzung trotz Cookie-Löschung. Die Profiltests prüfen Speichern und Leeren, Unicode-/Längengrenzen, gefälschte Ziel-IDs in beide Richtungen, die Abweisung unerlaubter Kontofelder und die Sperre für veraltete Sessions oder deaktivierte Konten beim Update. Passworttests prüfen falsches/altes/neues Passwort, Wiederholung und Eingabegrenzen, Schutz vor fremden Änderungen, Session- und CSRF-Wechsel, Widerruf anderer Sitzungen, konkurrierende Änderungen und die Versuchsbegrenzung nach erneutem Login.

Details und nächste Lernschritte stehen in [plan.md](plan.md).

Zusätzlich prüft `tests/registration-security.sql` nach Migration 003 die Datenbankrechte und beide Registrierungsarten in einer zurückgerollten Transaktion. `AUTH_TEST_LIVE=1 node tests/registration-flow.mjs php` prüft beide Abläufe gegen die echte Daten-API, mit privatem Mail-Testpostfach im temporären Verzeichnis und ohne Versand nach außen. Dazu gehören Registrierung und sofortiger Login ohne SMTP, Duplikate und parallele Anfragen, Rate Limits, die optionale Bestätigung samt abgelaufenen/ersetzten/verbrauchtem Token und der Browser-Fragmentübergang. `node tests/mail-transport.mjs php` testet den echten PHPMailer-Adapter gegen einen lokalen TLS-SMTP-Server einschließlich Ablehnung nicht vertrauenswürdiger Zertifikate; auch dabei geht keine Mail nach außen. Für diesen Test sind OpenSSL und die lokal installierten Composer-Abhängigkeiten erforderlich.

`tests/admin-security.sql` prüft nach Migration 004 alle Admin-Funktionen und Browser-Rechte in einer zurückgerollten Transaktion. `AUTH_TEST_LIVE=1 node tests/admin-flow.mjs php` prüft die echte PHP-Oberfläche mit temporären normalen und Admin-Konten: unberechtigte Aufrufe, Registrierung ohne Rollenerhöhung, CRUD, Suche/Seitenwechsel, CSRF, veraltete Formulare, Passwort-Neusetzen, Rollen-/Sitzungswiderruf, Selbstschutz und konkurrierende gegenseitige Herabstufung. Mit `ADMIN_TEST_BASE_URL=https://deine-seite/public/` kann derselbe Test die bereitgestellte Oberfläche prüfen. Dabei werden vorübergehend Testkonten in der konfigurierten Datenbank angelegt und anschließend entfernt; nur in einem dafür vorgesehenen Testprojekt ausführen.

## Quellen

- [PHP: password_hash](https://www.php.net/manual/en/function.password-hash.php)
- [PHP: password_verify](https://www.php.net/manual/en/function.password-verify.php)
- [Supabase: Daten-API](https://supabase.com/docs/guides/api)
- [Supabase: API-Schlüssel](https://supabase.com/docs/guides/getting-started/api-keys)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
