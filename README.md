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
1. composer require dergrossewirsing/ec-login-ext:dev-main
2. composer update dergrossewirsing/ec-login-ext
```bash
# Plugin über die Konsole installieren
php bin/console sw:plugin:refresh
php bin/console sw:plugin:install EcLoginExt
php bin/console sw:plugin:activate EcLoginExt

# Cache leeren und theme kompilieren
php bin/console sw:cache:clear
php bin/console sw:theme:compile
```

### 2. Datenbank-Migration
Das Plugin erstellt automatisch bei der Installation:
- E-Mail-Template `sECSECURELOGINLOCKOUT` für Benachrichtigungen
- Alle erforderlichen Freitextfelder in der `s_user_attributes` Tabelle

### 3. Template-Integration
Das Plugin erweitert automatisch das Standard-Login-Template um:
- Fehlermeldungen mit verbleibenden Versuchen
- JavaScript-Countdown bei aktiver Sperre
- Entsperr-Meldungen aus `/EcUnlock`

## Konfiguration

### Plugin-Einstellungen (Backend)
```
Grundkonfiguration / Plugins / EcLoginExt

- Maximale Fehlversuche: 3 (Standard)
- Sperrdauer: 24 Stunden (Standard)  
- E-Mail-Benachrichtigungen: Aktiviert
- Debug-Modus: Deaktiviert
```

### E-Mail-Templates anpassen
Die E-Mail-Vorlagen werden bei einer Erstinstallation automatisch importiert. 
Sie können diese unter **Einstellungen** > **E-Mail-Vorlagen** anpassen:
```
Views/mail/lockout-notification.html  # HTML-Version
Views/mail/lockout-notification.txt   # Text-Version
```

### Textbausteine (Snippets) anpassen
Die Textbausteine für Fehlermeldungen und Benachrichtigungen sind im Plugin enthalten
und werden bei der Installation automatisch importiert. 
Sie können diese unter **Einstellungen** > **Textbausteine** anpassen:
```bash
# Snippet-Import in Datenbank
php bin/console sw:snippets:to:db

# Verfügbare Namespaces:
# - account/login/locked/until 
# - account/login/locked/counter 
# - account/login/remaining
# - account/unlocked/invalid_token
# - account/unlocked/success
# - account/unlocked/not_found
# - account/unlocked/expired
# - account/unlocked/error
```

## Verwendung

### Für Endkunden
1. **Normaler Login**: Bei korrekten Zugangsdaten erfolgt die Anmeldung wie gewohnt
2. **Fehlversuche**: Nach jedem falschen Login wird die Anzahl verbleibender Versuche angezeigt
3. **Kontosperrung**: Nach 3 (konfigurierbar) Fehlversuchen erscheint eine Sperrung-Meldung mit Countdown
4. **E-Mail-Benachrichtigung**: Automatische E-Mail mit Entsperr-Link wird versendet (konfigurierbar)
5. **Vorzeitige Entsperrung**: Klick auf den Link in der E-Mail entsperrt das Konto sofort
6. **Automatische Entsperrung**: Nach 24(konfigurierbar) Stunden ist das Konto wieder verfügbar

### Für Shop-Betreiber
**Monitoring über s_mail_log Tabelle:**
```sql
SELECT * FROM s_mail_log WHERE type = 'sECSECURELOGINLOCKOUT' ORDER BY sent_at DESC;
```
**Manuelle Entsperrung eines Kunden:**
Im Backend unter **Kunden** > **Kunden verwalten**:
1. Suchen Sie den betroffenen Kunden
2. Klicken Sie auf **Bearbeiten**
3. Klicken Sie auf "Entsperren"

**Manuelle Entsperrung über Datenbank:**
```sql
UPDATE s_user_attributes 
SET ec_current_failed_attempts = 0, ec_locked_until = NULL 
WHERE userID = [CUSTOMER_ID];

UPDATE s_user
SET lockeduntil = NULL
WHERE id = [CUSTOMER_ID];
```

## Technische Details

### Architektur
- **LoginSecurityService**: Zentrale Geschäftslogik für Sperrmechanismen
- **AuthSubscriber**: Event-Handler für Shopware Login-Events
- **EcUnlock Controller**: Frontend-Controller für Token-Entsperrung
- **MailTemplateInstaller**: Automatische E-Mail-Template-Installation

### Events
Das Plugin reagiert auf folgende Shopware Events:
- `Shopware_Modules_Admin_Login_Failure` => Fehlversuch-Behandlung
- `Shopware_Modules_Admin_Login_Successful` => Reset bei erfolgreichem Login
- `Shopware_Modules_Admin_Login_FilterResult` => Fehlermeldung-Modifikation

### Sicherheitsmerkmale
- **Sichere Token-Generierung**: Verwendung von `random_bytes()` und `bin2hex()`
- **Zeitbasierte Token**: Unlock-Token haben eine Gültigkeitsdauer von 24 Stunden
- **SQL-Injection-Schutz**: Verwendung von Doctrine ORM/DBAL
- **E-Mail-Sicherheit**: E-Mail-Versand erfolgt über Shopware's integriertes Mail-System
- **Session-Schutz**: Nutzung von Shopware's Session-Management für sichere Datenübertragung

## Deinstallation

```bash
# Plugin deaktivieren und deinstallieren
php bin/console sw:plugin:deactivate EcLoginExt
php bin/console sw:plugin:uninstall EcLoginExt

# Datenbank-Bereinigung (optional)
DELETE FROM s_mail_log WHERE type = 'sECSECURELOGINLOCKOUT';
DELETE FROM s_core_config_mails WHERE name = 'sECSECURELOGINLOCKOUT';
```

## Kompatibilität

- **Shopware Version**: 5.6.0 oder höher (entwickelt und getestet mit 5.7.14)
- **PHP Version**: 8.0 oder höher (entwickelt auf PHP 8.0.21)
- **Datenbank**: MySQL 5.7+ / MariaDB 10.3+
- **Browser**: Moderne Browser mit JavaScript-Unterstützung

## Lizenz
Dieses Plugin wurde für Shopware 5 entwickelt und folgt den 
Shopware Plugin-Entwicklungsrichtlinien.