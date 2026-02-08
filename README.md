This documentation describes the **Tempest Weather Station** module (v3.6.3) for IP-Symcon.

### **1. Functionality / Funktionsumfang**

**EN:**  
The module acts as a local receiver for the Weatherflow Tempest Weather Station. It processes real-time data sent by the Tempest Hub via the UDP protocol and integrates it into the IP-Symcon ecosystem. Key features include advanced sensor data management, battery health analytics using linear regression, and a responsive, high-performance HTML5 dashboard with interactive Highcharts sparklines.

**DE:**  
Das Modul fungiert als lokaler Empfänger für die Weatherflow Tempest Wetterstation. Es verarbeitet Echtzeitdaten, die vom Tempest Hub über das UDP-Protokoll gesendet werden, und integriert diese in das IP-Symcon-Ökosystem. Zu den Hauptfunktionen gehören ein fortschrittliches Sensordatenmanagement, Analysen zum Batteriezustand mittels linearer Regression sowie ein responsives HTML5-Dashboard mit interaktiven Highcharts-Diagrammen.

---

### **2. Processes / Prozesse**

**EN:**

1.  **Data Acquisition:** Listens for incoming UDP JSON packets (Message types: `obs_st`, `rapid_wind`, `device_status`, `hub_status`, `evt_precip`, `evt_strike`).
2.  **Integrity & Back-filling:** Compares incoming timestamps with existing archive data to prevent duplicates and allow "back-filling" of late-arriving historical packets.
3.  **Battery Analytics:** Performs a "Least Squares" linear regression over a configurable number of battery voltage data points to determine the charge slope (Charging vs. Discharging).
4.  **Hysteresis Logic:** Dynamically adjusts system profiles and colors based on battery performance and station operating modes.
5.  **Visualization:** Generates a CSS-Grid-based HTML dashboard. It fetches historical data from the Archive Control to render 24h (configurable) sparklines using Highcharts.
6.  **Secure Webhook:** Serves the dashboard as a Progressive Web App (PWA). It validates users via a linked "Secrets Manager" instance for secure remote access.

**DE:**

1.  **Datenerfassung:** Lauscht auf eingehende UDP-JSON-Pakete (Typen: `obs_st`, `rapid_wind`, `device_status`, `hub_status`, `evt_precip`, `evt_strike`).
2.  **Integrität & Back-filling:** Vergleicht Zeitstempel mit Archivdaten, um Duplikate zu verhindern und das "Back-filling" von verspätet eintreffenden historischen Paketen zu ermöglichen.
3.  **Batterie-Analyse:** Führt eine „Least Squares“ lineare Regression über eine konfigurierbare Anzahl von Spannungswerten durch, um die Steigung (Laden vs. Entladen) zu bestimmen.
4.  **Hysterese-Logik:** Passt Systemprofile und Farben dynamisch basierend auf der Batterieleistung und den Betriebsmodi der Station an.
5.  **Visualisierung:** Erzeugt ein CSS-Grid-basiertes HTML-Dashboard. Es ruft historische Daten aus der Archivsteuerung ab, um 24h-Diagramme (konfigurierbar) mittels Highcharts zu rendern.
6.  **Sicherer Webhook:** Stellt das Dashboard als Progressive Web App (PWA) bereit. Zur Sicherheit wird der Zugriff über eine verknüpfte „Secrets Manager“-Instanz validiert.

---

### **3. Input Data / Eingangsdaten**

**EN:**

- **UDP Packets:** JSON formatted strings from the Tempest Hub (Port 50222).
- **Archive Data:** Historical values for battery voltage and sensor idents (used for regression and charts).
- **Configuration:** User-defined properties (Profile prefixes, Dashboard layout, Secrets Manager ID).
- **Secrets:** Credentials (User/Password) fetched dynamically from the Secrets Manager for Webhook authentication.

**DE:**

- **UDP-Pakete:** JSON-formatierte Strings vom Tempest Hub (Port 50222).
- **Archivdaten:** Historische Werte für Batteriespannung und Sensoren (genutzt für Regression und Diagramme).
- **Konfiguration:** Benutzerdefinierte Eigenschaften (Profil-Präfixe, Dashboard-Layout, Secrets Manager ID).
- **Secrets:** Zugangsdaten (Benutzer/Passwort), die dynamisch aus dem Secrets Manager für die Webhook-Authentifizierung abgerufen werden.

---

### **4. Output Data / Ausgangsdaten**

**EN:**

- **Status Variables:** Over 26 variables including Temperature, Humidity, Pressure, Wind (Avg/Gust/Lull/Dir), UV, Solar Radiation, and Lightning strikes.
- **Diagnostic Variables:** Battery Voltage, Regression Slope, System Condition (Charge/Discharge), and Signal Strength (RSSI).
- **Dashboard (HTMLBox):** A formatted HTML string for use in the IP-Symcon WebFront or Tile Visualizer.
- **Webhook (PWA):** A standalone web endpoint `/hook/tempest` providing a full-screen mobile experience with app manifest and icons.

**DE:**

- **Statusvariablen:** Über 26 Variablen, darunter Temperatur, Luftfeuchtigkeit, Druck, Wind (Schnitt/Böe/Ruhe/Richtung), UV, Sonnenstrahlung und Blitzeinschläge.
- **Diagnosevariablen:** Batteriespannung, Regressionssteigung, Systemzustand (Laden/Entladen) und Signalstärke (RSSI).
- **Dashboard (HTMLBox):** Ein formatierter HTML-String zur Verwendung im IP-Symcon WebFront oder Tile Visualizer.
- **Webhook (PWA):** Ein eigenständiger Web-Endpunkt `/hook/tempest`, der eine Vollbild-App-Erfahrung inklusive Manifest und Icons bietet.

---

### Version Management

- **Old Version:** 3.6.3
- **New Version:** 3.7.0
- **Commit Message:** `docs: add detailed functionality and data flow description in English and German`

- <img width="1005" height="749" alt="image" src="https://github.com/user-attachments/assets/15db2c8c-3b10-48be-b8d4-193369449cf5" />

