# EcLoginExt Plugin - Event-Dokumentation

## Übersicht

Das EcLoginExt Plugin erweitert Shopware 5 um erweiterte Login-Sicherheitsfunktionen. Es implementiert Konto-Sperrungen nach wiederholten fehlgeschlagenen Anmeldeversuchen und bietet E-Mail-basierte Entsperrung.

## Grundkonzept
Ziel: so viel Grundfunktion wie möglich bestehen lassen und keine Methoden komplett überschreiben.
Von daher nicht das NotifyUntil-Event in sAdmin genommen.
Herausforderung: Grundklasse nicht einfach erweiterbar weil private. Aber ein paar events.
Plan: Abtrennung der benötigten Datenfelder (Fehlversuche gesamt, Fehlversuche und sperrdatum). Diese werden zusammen 
mit neuen benötigten Feldern als s_user_attributes gespeichert, um keine bestehenden Core-Tabellen ändern zu müssen
und die Updatefähigkeit zu erhalten.
Mailversand durch Shopware-Bordmittel mit administrierbarem Mailtemplate und Nachvollziehbarkeit im Maillog.
Token-Unlock-Funktionalität durch eigenen Controller gelöst.

## Verwendete Shopware Events

Das Plugin nutzt verschiedene Shopware-Events, um sich nahtlos in den Login-Prozess zu integrieren. Nachfolgend eine detaillierte Auflistung aller verwendeten Events:

### 1. `Enlight_Controller_Action_PreDispatch_Frontend`

**Handler:** `AuthSubscriber::onPreDispatch()`

**Zweck:** Template-Verzeichnis registrieren

**Begründung der Auswahl:**
- Wird bei jedem Frontend-Controller-Aufruf ausgelöst
- Ermöglicht das Hinzufügen des Plugin-Template-Verzeichnisses zum Template-Manager
- Stellt sicher, dass Plugin-Templates systemweit verfügbar sind
- Erfolgt früh genug im Request-Zyklus, bevor Templates geladen werden

**Implementierung:**
```php
public function onPreDispatch(): void
{
    $this->templateManager->addTemplateDir($this->pluginDirectory . '/Views');
}
```

---

### 2. `Shopware_Modules_Admin_Login_Failure`

**Handler:** `AuthSubscriber::onLoginFailed()`

**Zweck:** Verarbeitung fehlgeschlagener Anmeldeversuche

**Begründung der Auswahl:**
- **Perfekte Timing**: Wird genau nach einem fehlgeschlagenen Login-Versuch ausgelöst
- **Verfügbare Daten**: Bietet Zugriff auf die verwendete E-Mail-Adresse
- **Core-Integration**: Nutzt Shopwares nativen Authentifizierungsflow
- **Nach Core-Logic**: Wird nach Shopwares eigener Validierung ausgeführt

**Implementierung:**
```php
public function onLoginFailed(\Enlight_Event_EventArgs $args): void
{
    $email = $args->get('email');
    if (!empty($email)) {
        $result = $this->loginSecurityService->handleFailedLogin($email);
        $session = Shopware()->Session();
        $session->set('EcSecureLoginResult', $result);
    }
}
```

**Warum nicht andere Events:**
- `Shopware_Modules_Admin_Login_Start` - Zu früh, kein Fehlschlag bekannt
- `Enlight_Controller_Action_PostDispatch` - Zu spät, Session könnte bereits verändert sein

---

### 3. `Shopware_Modules_Admin_Login_Successful`

**Handler:** `AuthSubscriber::onLoginSuccessful()`

**Zweck:** Zurücksetzen der Fehlversuche bei erfolgreichem Login

**Begründung der Auswahl:**
- **Erfolgs-Indikator**: Eindeutige Identifikation erfolgreicher Logins
- **Cleanup-Zeitpunkt**: Idealer Moment zum Zurücksetzen der Sicherheitszähler
- **User-Context**: Vollständige Kundendaten verfügbar
- **Atomarität**: Erfolgt innerhalb der Login-Transaktion

**Implementierung:**
```php
public function onLoginSuccessful(\Enlight_Event_EventArgs $args): void
{
    $email = $args->get('email');
    if (!empty($email)) {
        $customer = $this->getCustomerByEmail($email);
        if ($customer) {
            $this->loginSecurityService->handleSuccessfulLogin($customer);
        }
    }
}
```

---

### 4. `Shopware_Modules_Admin_Login_FilterResult`

**Handler:** `AuthSubscriber::onLoginFilterResult()`

**Zweck:** Blockierung gesperrter Konten und Anzeige von Sicherheitsmeldungen

**Begründung der Auswahl:**
- **Filter-Pattern**: Ermöglicht Manipulation des Login-Ergebnisses
- **Return-Kontrolle**: Kann Login-Erfolg in Fehlschlag umwandeln
- **Message-Injection**: Perfekt für Einblendung von Sicherheitsmeldungen
- **Last-Chance**: Letzte Möglichkeit, Login zu verhindern

**Implementierung:**
```php
public function onLoginFilterResult(\Enlight_Event_EventArgs $args): void
{
    $session = Shopware()->Session();
    
    if (!$session->has('EcSecureLoginResult')) {
        return;
    }
    
    $return = $args->getReturn();
    $result = $session->get('EcSecureLoginResult');
    
    // Blockiere gesperrte Accounts
    if ($result['locked']) {
        $return = [false, $this->buildLockMessages($result)];
        $args->setReturn($return);
    }
}
```

**Warum dieses Event:**
- `Enlight_Controller_Action_PostDispatch` - Zu spät für Login-Manipulation
- `Shopware_Modules_Admin_Login_Start` - Zu früh, keine Sicherheitsdaten verfügbar

---

### 5. `Enlight_Controller_Action_PostDispatch_Frontend_Account`

**Handler:** `AuthSubscriber::onAccountAction()`

**Zweck:** Template-Variablen für Account-Controller setzen und Session-Nachrichten verarbeiten

**Begründung der Auswahl:**
- **Controller-Spezifisch**: Zielt gezielt auf Account-Controller ab
- **Template-Integration**: PostDispatch ist ideal für Template-Variable-Zuweisung
- **Session-Cleanup**: Perfekter Zeitpunkt für Session-Nachrichten-Verarbeitung
- **View-Access**: Vollständiger Zugriff auf View-Objekt verfügbar

**Implementierung:**
```php
public function onAccountAction(\Enlight_Event_EventArgs $args): void
{
    $controller = $args->getSubject();
    $view = $controller->View();
    $session = Shopware()->Session();

    // Verarbeite Unlock-Controller Nachrichten
    if ($session->has('sErrorFlag') && $session->has('sErrorMessages')) {
        $view->assign('sErrorFlag', $session->get('sErrorFlag'));
        $view->assign('sErrorMessages', $session->get('sErrorMessages'));
        $session->remove('sErrorFlag');
        $session->remove('sErrorMessages');
    }
    
    // Weitere Template-Zuweisungen...
}
```

---

### 6. `Enlight_Controller_Action_PostDispatch_Frontend_Register`

**Handler:** `AuthSubscriber::onAccountAction()`  

**Zweck:** Template-Variablen auch für Register-Controller setzen

**Begründung der Auswahl:**
- **Unified-Handling**: Register-Controller zeigt auch Login-Formular
- **Cross-Controller-Messages**: Session-Nachrichten müssen auch hier verarbeitet werden
- **Template-Konsistenz**: Gleiche Sicherheitsmeldungen in beiden Controllern
- **User-Experience**: Einheitliche Fehlerbehandlung

---

## Event-Auswahl Strategien

### Warum diese spezifische Event-Kombination?

#### 1. **Vollständige Login-Flow-Abdeckung**
```
Login-Attempt → Login_Failure/Success → FilterResult → Template-Display
      ↓              ↓                      ↓              ↓
  PreDispatch → onLoginFailed/Success → onFilterResult → onAccountAction
```

#### 2. **Separation of Concerns**
- **Authentication Events**: Handhaben Geschäftslogik (Zähler, Sperrungen)
- **Controller Events**: Handhaben Präsentationslogik (Templates, Nachrichten)
- **Filter Events**: Handhaben Zugriffskontrolle (Blockierung)

#### 3. **Fehlertoleranz und Fallbacks**
- Events sind in der richtigen Reihenfolge registriert
- Jedes Event prüft Vorbedingungen
- Graceful Degradation bei Event-Fehlern

### Verworfene Event-Alternativen

#### `Enlight_Controller_Action_PreDispatch_Frontend_Account`
- **Problem**: Zu früh für Session-Daten-Verarbeitung
- **Grund**: Login-Events sind noch nicht ausgelöst

#### `Shopware_Modules_Admin_Login_Start`
- **Problem**: Keine Unterscheidung zwischen Erfolg/Fehlschlag
- **Grund**: Event wird vor Authentifizierung ausgelöst

#### `Enlight_Bootstrap_AfterInitResource_session`
- **Problem**: Zu generisch, wird zu oft ausgelöst
- **Grund**: Performance-Impact bei jeder Session-Initialisierung

## Template-Integration

### Template-Verzeichnis-Struktur
```
custom/plugins/EcLoginExt/Views/
├── frontend/
│   └── account/
│       └── login.tpl
└── email/
    └── lockout_notification.tpl
```

### Template-Variable-Übergabe
Das Plugin nutzt den PostDispatch-Hook zur Übergabe folgender Template-Variablen:

```php
// Sicherheitsmeldungen
$view->assign('sErrorFlag', $errorFlags);
$view->assign('sErrorMessages', $errorMessages);
$view->assign('sSuccessMessages', $successMessages);

// Sicherheitsstatus
$view->assign('EcAccountLocked', $isLocked);
$view->assign('EcLockUntil', $lockUntilDate);
$view->assign('EcSecureLoginResult', $loginResult);
```

## Session-Daten-Management

### Session-Schlüssel-Konventionen
```php
// Plugin-spezifische Session-Keys
'EcSecureLoginResult'    // Login-Ergebnis für Template
'sErrorFlag'             // Shopware-kompatible Error-Flags
'sErrorMessages'         // Shopware-kompatible Fehlermeldungen
'sSuccessMessages'       // Shopware-kompatible Erfolgsmeldungen
'unlock_attempts_[hash]' // Rate-Limiting für Entsperr-Versuche
```

### Session-Lifecycle
1. **onLoginFailed**: Speichert Sicherheitsergebnis in Session
2. **onFilterResult**: Liest und verarbeitet Sicherheitsdaten
3. **onAccountAction**: Überträgt Session-Daten zu Template-Variablen
4. **Cleanup**: Entfernt verarbeitete Session-Daten

## Sicherheitsüberlegungen

### Event-basierte Sicherheitsmaßnahmen

#### 1. **Timing-Attack-Prevention**
- Events werden in definierter Reihenfolge abgearbeitet
- Konstante Verarbeitungszeiten durch alle Event-Handler

#### 2. **Session-Hijacking-Schutz**
- Session-Token werden nach kritischen Events erneuert
- Sensible Daten werden nach Verarbeitung aus Session entfernt

#### 3. **Cross-Controller-Sicherheit**
- Sicherheitsdaten werden sicher zwischen Controllern übertragen
- Keine direkten Controller-zu-Controller-Abhängigkeiten

## Debugging und Logging

### Event-basiertes Logging
```php
// Jeder Event-Handler loggt seine Aktivitäten
error_log('EcLoginExt: Event [EventName] triggered for user: ' . $email);
```

### Debug-Informationen
- Event-Timing und -Reihenfolge
- Session-Datenfluss zwischen Events
- Template-Variable-Zuweisungen

## Performance-Überlegungen

### Event-Handler-Optimierung
- **Frühe Exits**: Event-Handler prüfen Vorbedingungen sofort
- **Lazy Loading**: Services werden nur bei Bedarf initialisiert
- **Caching**: Wiederholte Datenbankabfragen werden vermieden

### Memory Management
- Event-Handler vermeiden Speicher-intensive Operationen
- Session-Daten werden nach Verarbeitung bereinigt
- Temporäre Variablen werden explizit freigegeben

---

## Fazit

Die Event-Auswahl für das EcLoginExt Plugin basiert auf einer sorgfältigen Analyse der Shopware 5 Architektur. Jedes Event wurde spezifisch für seinen Zweck ausgewählt und bietet optimale Integration in den bestehenden Login-Flow ohne Beeinträchtigung der Core-Funktionalität.

Die Kombination aus Authentication-, Controller- und Filter-Events ermöglicht eine robuste und sichere Implementierung erweiterter Login-Sicherheit, die sich nahtlos in Shopware 5 integriert.

