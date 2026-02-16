# DEUTSCH (GERMAN)

### **1. Voraussetzungen (Preconditions)**
*   **IP-Symcon Version:** 5.0 oder höher.
*   **UDP-Socket:** Es muss manuell ein UDP-Socket in IP-Symcon erstellt werden.
    *   **Port:** 50222.
    *   **Verbindung:** Der Socket muss als übergeordnete Instanz (Parent) des Tempest-Moduls verbunden sein.
*   **Archiv-Steuerung (Archive Control):** Erforderlich für die Datenintegrität (Back-filling), die Batterie-Regression und die Darstellung von Diagrammen.
*   **Secrets Manager (Optional):** Erforderlich, wenn die Webhook-Authentifizierung über den verschlüsselten Tresor erfolgen soll.

### **2. Funktionalität**
Das Modul ist ein spezialisierter lokaler Empfänger für die Weatherflow Tempest Wetterstation. Es verarbeitet UDP-Broadcast-Pakete in Echtzeit, stellt die Datenintegrität durch Abgleich mit der Archiv-Steuerung sicher und bietet erweiterte Analysen zur Batterieleistung. Über einen integrierten Webhook wird ein responsives HTML5-Dashboard als Progressive Web App (PWA) bereitgestellt.

### **3. Spezifikation der Eingangsdaten**

#### **A. UDP-Payload (Tempest Hub)**
*   **Format:** UTF-8 kodierte JSON-Strings.
*   **Wichtige Datentypen:**
    *   `obs_st`: Haupt-Beobachtungspaket (Temperatur, Feuchte, Druck, Lux, UV, Regen, Batterie).
    *   `rapid_wind`: Hochfrequente Winddaten (Geschwindigkeit und Richtung).
    *   `device_status` / `hub_status`: Diagnosewerte (RSSI, Uptime, Firmware).
    *   `evt_precip` / `evt_strike`: Ereignis-Pakete für Regenbeginn und Blitzeinschläge.

#### **B. Secrets Manager (Array-Struktur)**
Wenn der `AuthMode` auf "Secrets Manager" eingestellt ist, ruft das Modul Daten vom Ident `Webhooks` ab. Die erwartete Struktur des assoziativen Arrays ist:
```php
[
    "Tempest" => [
        "User" => "string", // Benutzername für Webhook-Authentifizierung
        "PW"   => "string"  // Passwort für Webhook-Authentifizierung
    ]
]
```
1. Passkey
Diese Erweiterung ermöglicht den Zugriff auf das Dashboard mittels moderner biometrischer Authentifizierung (Passkey). Anstatt Benutzername und Passwort manuell einzugeben, wird die Identität des Nutzers über das gesicherte Portal des Secrets Managers (z. B. via Fingerabdruck oder Gesichtserkennung) verifiziert.
2. Prozesse
Authentifizierungs-Prüfung: Beim Aufruf des Webhooks (/hook/tempest) prüft das Modul, ob der Modus "Passkey" aktiviert ist.
Portal-Validierung: Das Modul kommuniziert intern mit der Secrets-Manager-Instanz und ruft die Funktion SEC_IsPortalAuthenticated auf.
Dynamische Weiterleitung: Falls keine gültige Sitzung vorliegt, generiert das Modul automatisch eine verschlüsselte Rücksprung-URL (return) und leitet den Browser zum biometrischen Login-Portal des Secrets Managers um.
Session-Management: Nach erfolgreicher biometrischer Bestätigung leitet das Portal den Nutzer zurück zum Dashboard, welches nun freigeschaltet ist.
3. Eingangsdaten
Konfiguration: Auswahl des Authentifizierungsmodus 3 und die Instanz-ID des Secrets Managers.
Anfrage: Der HTTP-Request des Browsers inklusive der aktuellen Session-Informationen.
4. Ausgangsdaten
HTTP-Header: Ein Location-Redirect zum Login-Portal im Falle einer fehlenden Authentifizierung.
Visualisierung: Die Dashboard-HTML-Seite (nur nach erfolgreicher biometrischer Verifizierung).

### **4. Prozesse & Logik**

*   **Datenintegrität (Back-filling):** Jedes eingehende Paket wird gegen das Archiv geprüft. Wenn ein Paket verspätet eintrifft (identifiziert als `OLD_TIME_STAMP`), wird es unter Verwendung des Original-Zeitstempels via `AC_AddLoggedValues` ins Archiv nachgetragen.
*   **Batterie-Regression:** Das Modul nutzt den "Least Squares"-Algorithmus, um die Steigung der letzten 45 Spannungswerte zu berechnen. Wenn die Steigung den Schwellwert `TriggerValueSlope` überschreitet, wechselt der Status auf "Laden".
*   **Dynamische Profile:** Basierend auf der Regressionsanalyse werden die Variablenprofile für den Systemzustand (`System_Condition`) zur Laufzeit mit neuen Textassoziationen und Farben überschrieben.
*   **Dashboard-Rendering:** Erzeugt ein CSS-Grid-Dashboard. Für aktivierte Diagramme werden die letzten 24 Stunden (konfigurierbar) an historischen Daten aus dem Archiv abgerufen und als Highcharts-Sparklines gerendert.

### **5. Verhalten in Grenzfällen (Edge Cases)**
*   **Korrupte Sensordaten:** Wenn Temperatur und Luftfeuchtigkeit beide exakt 0,0 liefern, wird das Paket verworfen, um Fehlberechnungen in der Logik zu vermeiden.
*   **Deaktiviertes Logging:** Diagramme im Dashboard zeigen den Hinweis `(Logging Off)`. Die Batterie-Regression wird abgebrochen und eine `KL_WARNING` im Meldungsfenster ausgegeben.
*   **Systemstart:** Das Modul pausiert alle instanzübergreifenden Aufrufe, bis der Kernel den Status `KR_READY` (10103) erreicht hat, um "Interface not available"-Fehler zu verhindern.
*   **Gelöschte Variablen:** Wenn ein Benutzer eine Variable manuell löscht, erkennt das Modul den fehlenden Ident und loggt eine Warnung, anstatt einen Fatal Error zu erzeugen.

### **6. Ausgangsdaten**
*   **Statusvariablen:** Über 26 Sensoren inklusive Wind- und Lichtwerten.
*   **Diagnosevariablen:** Regressionssteigung, Batteriestatus, Systemzustand und Zeit-Deltas.
*   **HTML Dashboard:** Eine formatierte HTML-Variable mit eingebetteten Highcharts-Diagrammen.
*   **PWA Endpunkt:** Ein Webhook unter `/hook/tempest` inklusive Web-App-Manifest und mobilen Icons für iOS/Android.

---

# ENGLISH

### **1. Preconditions**
*   **IP-Symcon Version:** 5.0 or higher.
*   **UDP Socket:** A UDP Socket must be manually created.
    *   **Port:** 50222.
    *   **Connection:** The socket must be connected as the "Parent" of the Tempest instance.
*   **Archive Control:** Required for data integrity (back-filling), battery regression, and chart rendering.
*   **Secrets Manager (Optional):** Required if Webhook authentication is to be handled via the encrypted vault.

### **2. Functionality**
The module serves as a specialized local receiver for the Weatherflow Tempest Weather Station. It processes real-time UDP broadcast packets, ensures data integrity by synchronizing with the Archive Control, and provides advanced battery performance analytics. An integrated Webhook delivers a responsive HTML5 dashboard as a Progressive Web App (PWA).

### **3. Input Data Specification**

#### **A. UDP Payload (Tempest Hub)**
*   **Format:** UTF-8 encoded JSON strings.
*   **Key Message Types:**
    *   `obs_st`: Primary observation packet (Temp, Humidity, Pressure, Lux, UV, Rain, Battery).
    *   `rapid_wind`: High-frequency wind speed and direction.
    *   `device_status` / `hub_status`: Diagnostics (RSSI, Uptime, Firmware).
    *   `evt_precip` / `evt_strike`: Event packets for rain start and lightning strikes.

#### **B. Secrets Manager (Array Structure)**
If the `AuthMode` is set to "Secrets Manager", the module retrieves data from the `Webhooks` ident. The expected associative array structure is:
```php
[
    "Tempest" => [
        "User" => "string", // Username for Webhook basic auth
        "PW"   => "string"  // Password for Webhook basic auth
    ]
]
```
1. Passkey
This enhancement enables access to the dashboard using modern biometric authentication (Passkey). Instead of manually entering a username and password, the user's identity is verified through the secured portal of the Secrets Manager (e.g., via fingerprint or facial recognition).
2. Processes
Authentication Check: When the webhook (/hook/tempest) is accessed, the module checks if the "Passkey" mode is active.
Portal Validation: The module communicates internally with the Secrets Manager instance and calls the function SEC_IsPortalAuthenticated.
Dynamic Redirection: If no valid session exists, the module automatically generates an encrypted return URL (return) and redirects the browser to the biometric login portal of the Secrets Manager.
Session Management: Upon successful biometric confirmation, the portal redirects the user back to the dashboard, which is then unlocked.
3. Input Data
Configuration: Selection of authentication mode 3 and the Instance ID of the Secrets Manager.
Request: The HTTP request from the browser, including current session information.
4. Output Data
HTTP Header: A Location redirect to the login portal in case of missing authentication.
Visualization: The Dashboard HTML page (only after successful biometric verification).
### **4. Processes & Logic**

*   **Data Integrity (Back-filling):** Every incoming packet is validated against the Archive. If a packet arrives late (identified as `OLD_TIME_STAMP`), it is retroactively inserted into the archive at its original timestamp using `AC_AddLoggedValues`.
*   **Battery Regression:** Uses the "Least Squares" algorithm to calculate the slope of the last 45 voltage points. If the slope exceeds the `TriggerValueSlope`, the status switches to "Charging".
*   **Dynamic Profiles:** Based on regression results, the variable profiles for `System_Condition` are overwritten at runtime with specific text associations and colors.
*   **Dashboard Rendering:** Generates a CSS-Grid dashboard. For enabled charts, it fetches the last 24 hours (configurable) of historical data from the Archive and renders them as Highcharts sparklines.

### **5. Behavior on Edge Cases**
*   **Corrupt Sensor Data:** If Temperature and Humidity both return exactly 0.0, the packet is discarded to prevent logic errors.
*   **Disabled Logging:** Dashboard charts display `(Logging Off)`. Battery regression terminates and logs a `KL_WARNING` in the message log.
*   **System Startup:** The module suspends cross-instance calls until the Kernel reaches `KR_READY` (10103) to prevent "Interface not available" errors.
*   **Deleted Variables:** If a user manually deletes a variable, the module detects the missing ident and logs a warning instead of triggering a Fatal Error.

### **6. Output Data**
*   **Status Variables:** 26+ sensors including wind and light data.
*   **Diagnostic Variables:** Regression slope, battery status, system condition, and time deltas.
*   **HTML Dashboard:** A formatted HTML variable with embedded Highcharts sparklines.
*   **PWA Endpoint:** A standalone Webhook at `/hook/tempest` including a web app manifest and mobile icons for iOS/Android.

---
- <img width="1005" height="749" alt="image" src="https://github.com/user-attachments/assets/15db2c8c-3b10-48be-b8d4-193369449cf5" />
- <img width="448" height="966" alt="image" src="https://github.com/user-attachments/assets/f853a55a-8271-4fc2-9f3c-98946589c9b6" />
- <img width="594" height="808" alt="image" src="https://github.com/user-attachments/assets/e3ebd7cc-1f2f-4d3d-9b4e-5ed5d8951661" />



