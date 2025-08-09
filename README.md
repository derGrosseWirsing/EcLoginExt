# EcLoginExt - Erweitertes Login-Sicherheitssystem

Ein Shopware 5 Plugin zur Verbesserung der Login-Sicherheit mit erweiterten Sperrmechanismen, E-Mail-Benachrichtigungen und Token-basierter Entsperrung.

## Features

- **Konfigurierbare Kontosperrung**: Nach 3 fehlgeschlagenen Login-Versuchen wird das Konto f�r 24 Stunden gesperrt
- **Doppelte Zählung**: Separate Zähler für aufeinanderfolgende Fehlversuche und Gesamtstatistik
- **E-Mail-Benachrichtigungen**: Automatischer Versand bei Kontosperrung mit Entsperr-Link
- **Token-basierte Entsperrung**: Vorzeitige Freischaltung über sicheren Unlock-Token
- **Benutzerfreundliche Meldungen**: Klare Kommunikation mit verbleibenden Versuchen
- **JavaScript-Countdown**: Live-Anzeige der verbleibenden Sperrzeit
- **Mehrsprachigkeit**: Unterstützung für Deutsch und Englisch
- **Event-basierte Architektur**: Nutzt Shopware Events ohne Core-Änderungen

## Installation

### 1. Plugin-Installation
## 1.1 Hochladen und Aktivieren
Laden Sie das Plugin in den `custom/plugins` Ordner Ihres Shopware 5 Systems
```bash
# Plugin über die Konsole installieren
php bin/console sw:plugin:refresh
php bin/console sw:plugin:install EcLoginExt
php bin/console sw:plugin:activate EcLoginExt

# Cache leeren und theme kompilieren
php bin/console sw:cache:clear
php bin/console sw:theme:compile
```
## 1.2 Manuelle Installation
Alternativ können Sie das Plugin über das Shopware Backend installieren:
1. Gehen Sie zu **Einstellungen** > **Plugin-Manager**
2. Klicken Sie auf **Plugin hochladen**
3. Wählen Sie die ZIP-Datei des Plugins aus
4. Aktivieren Sie das Plugin nach der Installation
5. Leeren Sie den Cache und kompilieren Sie das Theme

## 1.2 Composer-Installation
Falls Sie Composer verwenden, fügen Sie das Plugin zu Ihrer `composer.json` hinzu:
composer require dergrossewirsing/ec-login-ext:dev-main


### 2. Datenbank-Migration
Das Plugin erstellt automatisch bei der Installation:
- Tabelle `ec_customer_security` für Sicherheitsdaten
- E-Mail-Template `sECSECURELOGINLOCKOUT` für Benachrichtigungen

### 3. Template-Integration
Das Plugin erweitert automatisch das Standard-Login-Template um:
- Fehlermeldungen mit verbleibenden Versuchen
- JavaScript-Countdown bei aktiver Sperre
- Entsperr-Formular �ber `/EcUnlock`

## Konfiguration

### Plugin-Einstellungen (Backend)
```
Grundkonfiguration � Plugins � EcLoginExt

- Maximale Fehlversuche: 3 (Standard)
- Sperrdauer: 24 Stunden (Standard)  
- E-Mail-Benachrichtigungen: Aktiviert
- Debug-Modus: Deaktiviert
```

### E-Mail-Templates anpassen
Die E-Mail-Vorlagen befinden sich in:
```
Views/mail/lockout-notification.html  # HTML-Version
Views/mail/lockout-notification.txt   # Text-Version
```

### Textbausteine (Snippets) anpassen
```bash
# Snippet-Import in Datenbank
php bin/console sw:snippets:to:db

# Verf�gbare Namespaces:
# - frontend/ec_login_ext/account/login/remaining
# - frontend/ec_login_ext/account/login/locked/until
```

## Verwendung

### F�r Endkunden
1. **Normaler Login**: Bei korrekten Zugangsdaten erfolgt die Anmeldung wie gewohnt
2. **Fehlversuche**: Nach jedem falschen Login wird die Anzahl verbleibender Versuche angezeigt
3. **Kontosperrung**: Nach 3 Fehlversuchen erscheint eine Sperrung-Meldung mit Countdown
4. **E-Mail-Benachrichtigung**: Automatische E-Mail mit Entsperr-Link wird versendet
5. **Vorzeitige Entsperrung**: Klick auf den Link in der E-Mail entsperrt das Konto sofort
6. **Automatische Entsperrung**: Nach 24 Stunden ist das Konto wieder verf�gbar

### F�r Shop-Betreiber
**Monitoring �ber s_mail_log Tabelle:**
```sql
SELECT * FROM s_mail_log WHERE type = 'sECSECURELOGINLOCKOUT' ORDER BY sent_at DESC;
```

**Manuelle Entsperrung �ber Datenbank:**
```sql
UPDATE ec_customer_security 
SET consecutive_failed_attempts = 0, locked_until = NULL 
WHERE customer_id = [CUSTOMER_ID];
```

**Sicherheitsstatistiken abrufen:**
```sql
SELECT 
    c.email,
    ecs.consecutive_failed_attempts,
    ecs.total_failed_attempts,
    ecs.locked_until,
    ecs.last_failed_attempt
FROM ec_customer_security ecs
JOIN s_user c ON ecs.customer_id = c.id
WHERE ecs.total_failed_attempts > 0
ORDER BY ecs.last_failed_attempt DESC;
```

## Technische Details

### Architektur
- **LoginSecurityService**: Zentrale Gesch�ftslogik f�r Sperrmechanismen
- **AuthSubscriber**: Event-Handler f�r Shopware Login-Events
- **EcCustomerSecurity Entity**: Doctrine-basierte Datenpersistierung
- **EcUnlock Controller**: Frontend-Controller f�r Token-Entsperrung
- **MailTemplateInstaller**: Automatische E-Mail-Template-Installation

### Events
Das Plugin reagiert auf folgende Shopware Events:
- `Shopware_Modules_Admin_Login_Failure` � Fehlversuch-Behandlung
- `Shopware_Modules_Admin_Login_Successful` � Reset bei erfolgreichem Login
- `Shopware_Modules_Admin_Login_FilterResult` � Fehlermeldung-Modifikation

### Sicherheitsmerkmale
- **Sichere Token-Generierung**: Verwendung von `random_bytes()` und `bin2hex()`
- **Zeitbasierte Token**: Unlock-Token haben eine G�ltigkeitsdauer von 24 Stunden
- **SQL-Injection-Schutz**: Verwendung von Doctrine ORM/DBAL
- **XSS-Schutz**: Proper Output-Escaping in Templates
- **Rate-Limiting**: Schutz vor Brute-Force-Angriffen

## Debugging

### Log-Dateien
```bash
# Shopware Logs
tail -f var/log/core_dev.log | grep "EcLoginExt"

# E-Mail Logs (in Datenbank)
SELECT * FROM s_mail_log WHERE type LIKE '%ECSECURE%';
```

### Debug-Modus aktivieren
```php
// In plugin.xml oder Backend-Konfiguration
<config-element type="boolean">
    <name>debugMode</name>
    <label>Debug-Modus aktivieren</label>
    <value>false</value>
</config-element>
```

## Deinstallation

```bash
# Plugin deaktivieren und deinstallieren
php bin/console sw:plugin:deactivate EcLoginExt
php bin/console sw:plugin:uninstall EcLoginExt

# Datenbank-Bereinigung (optional)
DROP TABLE IF EXISTS ec_customer_security;
DELETE FROM s_mail_log WHERE type = 'sECSECURELOGINLOCKOUT';
DELETE FROM s_core_config_mails WHERE name = 'sECSECURELOGINLOCKOUT';
```

## Support

Bei Problemen oder Fragen:
1. �berpr�fen Sie die Shopware-Logs auf Fehlermeldungen
2. Kontrollieren Sie die Datenbank-Verbindung und -Berechtigungen
3. Stellen Sie sicher, dass alle required PHP-Extensions verf�gbar sind
4. Testen Sie die E-Mail-Konfiguration des Shops

## Kompatibilit�t

- **Shopware Version**: 5.6.0 oder h�her
- **PHP Version**: 7.4 oder h�her
- **Datenbank**: MySQL 5.7+ / MariaDB 10.3+
- **Browser**: Moderne Browser mit JavaScript-Unterst�tzung

## Lizenz

Dieses Plugin wurde f�r Shopware 5 entwickelt und folgt den Shopware Plugin-Entwicklungsrichtlinien.