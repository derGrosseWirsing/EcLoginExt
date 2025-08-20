## Shopware 5 Login-Architektur und Herausforderungen

### Standard Shopware-Flow für fehlgeschlagene Anmeldungen:

**Frontend-Login-Prozess:**
1. Kunde gibt Zugangsdaten im Frontend-Login-Formular ein
2. POST-Request wird an `AccountController->loginAction()` gesendet  
3. AccountController delegiert an `sAdmin->sLogin()` (Core-Authentifizierung)
4. `sLogin()` validiert Zugangsdaten über `sAdmin->sCheckUser()`
5. Bei falschen Zugangsdaten ruft `sLogin()` die Methode `sAdmin->failedLoginUser()` auf
6. `failedLoginUser()` implementiert Shopwares Standard-Sperrmechanismus:
   - Zählt Fehlversuche in `s_user.failed_logins`  
   - Sperrt Account nach 10 Versuchen für 30 Minuten
   - Speichert Sperrzeit in `s_user_attributes.lockeduntil`
7. `failedLoginUser()` feuert Event `Shopware_Modules_Admin_Login_Failure`
8. `sLogin()` feuert Event `Shopware_Modules_Admin_Login_FilterResult` für Fehlermeldungen
9. AccountController gibt Fehlermeldungen an Smarty-Template weiter

### Technische Herausforderungen:

**Limitierte Erweiterbarkeit:**
- `sAdmin->sLogin()` ist eine große Methode mit kritischer Authentifizierungslogik
- `sAdmin->failedLoginUser()` hat **private Sichtbarkeit** → nicht hookbar
- Standard-Sperrparameter (10 Versuche, 30 Min) sind **hart im SQL-Statement codiert**
- Keine Konfigurationsmöglichkeiten für Versuchsanzahl oder Sperrdauer
- Fehlende E-Mail-Benachrichtigung oder Unlock-Token-System

**Architektur-Beschränkungen:**
- Shopware Core verwendet direkte SQL-Updates statt Doctrine ORM
- Keine Trennung zwischen Fehlversuch-Zählung und Sperrlogik (Sperrzeit wird trotz evtl. vorhanderner Sperrzeit bei jedem Fehlversuch erhöht) 
- Session-Management ist tief in sLogin() integriert
- Template-Integration erfolgt über komplexe Array-Rückgaben

### Mögliche Eingriffspunkte:

**Hook-basierte Ansätze:**
- `AccountController->loginAction()` Hook (before/replace)
- `sAdmin->sLogin()` Hook (before/replace) - **nicht empfohlen**

**Event-basierte Integration:**
1. `Shopware_Modules_Admin_Login_Start` (notifyUntil) - Vor Authentifizierung
2. `Shopware_Modules_Admin_Login_Failure` (notify) - Nach fehlgeschlagenem Login  
3. `Shopware_Modules_Admin_Login_Successful` (notify) - Nach erfolgreichem Login
4. `Shopware_Modules_Admin_Login_FilterResult` (filter) - Modifikation der Fehlermeldungen
5. `Enlight_Controller_Action_PostDispatch_Frontend_Account` - Template-Integration

### Lösung: ###

**1. Datenmodell und Persistierung:**
- Separate Zähler für aufeinanderfolgende Fehlversuche und Gesamtanzahl aller Fehlversuche
- Sperrzeit-Management mit konfigurierbarer 24-Stunden-Sperre und Fehlversuch - Limit
- Unlock-Token System für vorzeitige Entsperrung
- Erweiterung der Shopware Backend Entsperrungs-Logik für manuelle Entsperrung

**2. Event-basierte Architektur:**
- `AuthSubscriber` registriert sich für Shopware Login-Events:
  - `Shopware_Modules_Admin_Login_Failure` → `onLoginFailed()`
  - `Shopware_Modules_Admin_Login_Successful` → `onLoginSuccessful()`  
  - `Shopware_Modules_Admin_Login_FilterResult` → `onLoginFilterResult()`
- Nutzt Shopware Session-System für Datentransfer zwischen Events

**3. Kernlogik - LoginSecurityService:**
- `handleFailedLogin()`: Verwaltet Fehlversuche, Sperrungen und E-Mail-Versand
- `handleSuccessfulLogin()`: Reset der aufeinanderfolgenden Fehlversuche
- `generateUnlockToken()`: Erstellt sichere Entsperr-Token mit Gültigkeitsdauer
- `unlockCustomerByToken()`: Verarbeitet Token-basierte Entsperrung

**4. Benutzerfreundliche Kommunikation:**
- Dynamische Meldungen über verbleibende Versuche vor Sperre
- JavaScript-Countdown bei aktiver Sperre zeigt verbleibende Zeit
- Mehrsprachige Snippet-Integration (Deutsch/Englisch)
- Custom Frontend-Controller `EcUnlock` für Token-basierte Entsperrung

**5. E-Mail-Benachrichtigung:**
- Automatischer Versand bei Kontosperrung
- HTML- und Text-Templates über `MailTemplateInstaller`
- Entsperr-Link mit eindeutigem Token für vorzeitige Freischaltung
- Integration in Shopware Mail-System mit Logging

**6. Installation und Konfiguration:**
- Database-Schema-Update über Plugin-Installation
- Mail-Template-Installation mit UTF-8 Encoding
- Konfigurierbare Parameter (Versuche, Sperrdauer) über Plugin-Config

Diese Lösung umgeht die Limitierungen der privaten `failedLoginUser()` Methode durch Nutzung der öffentlichen 
Shopware Events und erweitert das System um moderne Sicherheitsfeatures ohne Core-Änderungen.

## Warum alternative Lösungsansätze problematisch wären: ##

### AccountController LoginAction-Hook (before/replace): ### 
Ein Hook der `loginAction()` Methode wäre nicht sehr invasiv, würde aber dennoch erhebliche Probleme verursachen. Man müsste die gesamte Login-Logik der Action duplizieren (Request-Verarbeitung, Validierung, Template-Zuweisung, Weiterleitung), was zu Code-Duplikation und Wartbarkeitsproblemen führt. Bei Shopware-Updates könnten sich die internen Abläufe der `loginAction()` ändern, wodurch der Hook inkompatibel wird. Zudem würde man die bewährte Shopware-Logik für Session-Management, CSRF-Schutz und Template-Integration verlieren oder manuell nachbauen müssen.

### sAdmin->sLogin() Hook (before/replace): ###
Das Hooking der sLogin()-Methode ist noch problematischer, da diese Methode zentrale Authentifizierungslogik enthält 
(Session-Management, Cookie-Handling, Gruppen-Validierung). Ein Replace-Hook würde hunderte Zeilen kritischer 
Shopware-Funktionalität duplizieren müssen, was bei Updates zu schwerwiegenden Sicherheitslücken führen könnte.

### Core-Modifikationen: ### 
Direkte Änderungen an Shopware-Kerndateien wären die schlechteste Lösung, da sie bei jedem Update überschrieben werden 
und das System instabil machen. Außerdem würde dies die Shopware-Lizenz verletzen und Support-Ansprüche zunichte machen.

### Session-/Cookie-basierte Lösungen: ### 
Reine Frontend-Sperren über Sessions oder Cookies bieten keine echte Sicherheit, da sie clientseitig umgangen werden 
können. Ohne Datenbankpersistierung gehen Sperrdaten bei Server-Neustart verloren.

Die Event-basierte Lösung ist dagegen minimal-invasiv, update-sicher, testbar und nutzt Shopwares eigene Architektur-Patterns.
