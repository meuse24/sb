# Supabase mit PHP lernen

Eine kleine PHP-Testwebsite für World4You. Der erste Schritt prüft PHP-Sessions und die HTTPS-Verbindung zu Supabase. Login und Profile folgen in weiteren Etappen.

## Konfiguration

`config/config.example.php` nach `config/config.local.php` kopieren und Projekt-URL sowie Publishable Key eintragen. Diese lokale Datei wird von Git ignoriert. Keine Secret-Keys oder Datenbankpasswörter einsetzen.

## Ausführen und bereitstellen

`public/` ist das öffentliche Webverzeichnis. `app/` und `config/` nach Möglichkeit außerhalb des öffentlichen Webverzeichnisses ablegen, mit unveränderter relativer Ordnerstruktur.

Wenn alle drei Ordner auf einem Apache-Webspace innerhalb des öffentlichen Verzeichnisses liegen, müssen die mitgelieferten `.htaccess`-Dateien den Zugriff auf `app/` und `config/` sperren. Dies zuerst mit einer harmlosen Probe-Datei über HTTPS prüfen, bevor die lokale Konfiguration hochgeladen wird. Die Website liegt dann unter dem Unterpfad `public/`.

Nur `app/`, `config/` und `public/` gezielt bereitstellen. `ftp.txt`, `supabase.txt`, `.git/` und andere lokale Projektdateien nicht hochladen. FTPS mit Zertifikatsprüfung verwenden.

Die Anwendung benötigt PHP 8.4, cURL, Sessions und HTTPS. Der lokale PHP-Entwicklungsserver über unverschlüsseltes HTTP eignet sich wegen des absichtlich gesetzten Secure-Cookies nicht zum Prüfen der Session.

## Prüfen

1. Seite zweimal aufrufen: Der Sitzungszähler muss steigen.
2. „Verbindung testen“ ausführen: HTTP 200 sowie E-Mail-Einstellungen erscheinen.
3. POST ohne gültiges CSRF-Token: HTTP 403.
4. Wiederholter Test innerhalb von zehn Sekunden: HTTP 429.
5. Direkte HTTP-Zugriffe auf Konfiguration und Anwendungshilfen: HTTP 403.

Der Verbindungstest prüft die Auth-API, keine Anmeldung und keine Tabellenrechte. Das Formular ist eine vorübergehende Lern-Diagnose und wird beim Ausbau durch die eigentliche Anwendung ersetzt.

Details und Lernschritte stehen in [plan.md](plan.md).
