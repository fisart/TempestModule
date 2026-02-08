To ensure a perfect transition to your new development thread, here is the detailed technical description of the **Weather Module Tempest (v3.1.0)**.

---

### English: Technical Documentation & Functional Overview

**1. Core Functionality**
The module is an object-oriented device driver for IP-Symcon designed to process local UDP broadcasts from the Weatherflow Tempest station. It transforms raw JSON packets into structured system variables and provides a modern, responsive HTML visualization.

**2. Data Processes**

- **UDP Reception & Dispatching:** The module acts as a Data Sink for a UDP Socket (Port 50222). It parses incoming JSON and routes it to specific handlers for observations (`obs_st`), device status, hub status, rapid wind, and event-based data (rain/lightning).
- **Data Integrity Logic:**
  - _Duplicate Detection:_ Prevents multiple entries of the same observation.
  - _Back-filling:_ Detects late-arriving packets via timestamps and uses the Archive Control to insert data into the correct historical position, followed by automatic re-aggregation.
- **Battery Analytics (Machine Learning):** Uses a **Least Squares Linear Regression** to calculate the voltage slope over a configurable number of data points. It implements a hysteresis logic to determine the "Battery Status" (Charging/Discharging) and dynamically adjusts variable profile associations based on the power state.
- **Blueprint 2.0 UI Management:** Implements a stable dynamic configuration list using **RAM-Caching (Attributes)**. This bypasses the browser's form-buffer limitations, ensuring that variable selections for the dashboard are never lost.

**3. Input Data**

- **Source:** Broadcasted JSON packets via UDP.
- **Types:** `obs_st` (18+ weather metrics), `device_status` (voltage, rssi), `hub_status`, `rapid_wind`, `evt_precip`, `evt_strike`.
- **Configuration:** User-defined station name, profile prefixes, regression parameters, and a coordinate-based grid selection list.

**4. Output Data**

- **System Variables:** Structured Floats, Integers, and Booleans for all weather data, technical deltas (`time_delta`, `stamp_delta`), and battery metrics.
- **HTML Dashboard:** A responsive grid-based visualization served via an internal Webhook (`/hook/tempest`).
- **Scaling:** Uses CSS Container Queries (`cqi`) and `clamp()` to scale fonts and layouts relative to the size of the HTML box in the visualization.

---

### Deutsch: Technische Dokumentation & Funktionsübersicht

**1. Kernfunktionalität**
Das Modul ist ein objektorientierter Gerätetreiber für IP-Symcon zur Verarbeitung lokaler UDP-Broadcasts der Weatherflow Tempest Station. Es wandelt rohe JSON-Pakete in strukturierte Systemvariablen um und bietet eine moderne, responsive HTML-Visualisierung.

**2. Datenprozesse**

- **UDP-Empfang & Dispatching:** Das Modul fungiert als Data Sink für einen UDP-Socket (Port 50222). Es parst eingehendes JSON und leitet es an spezifische Handler für Beobachtungen (`obs_st`), Gerätestatus, Hub-Status, Rapid Wind und ereignisbasierte Daten (Regen/Blitz) weiter.
- **Daten-Integritätslogik:**
  - _Duplikaterkennung:_ Verhindert Mehrfacheinträge derselben Beobachtung.
  - _Back-filling:_ Erkennt verspätete Pakete anhand von Zeitstempeln und nutzt das Archiv, um Daten an der korrekten historischen Position einzufügen, gefolgt von einer automatischen Re-Aggregation.
- **Batterie-Analyse (Machine Learning):** Verwendet eine **Lineare Regression (Least Squares)**, um die Spannungsteigung über eine konfigurierbare Anzahl von Datenpunkten zu berechnen. Eine Hystereselogik bestimmt den "Batteriestatus" (Laden/Entladen) und passt die Profil-Assoziationen der Variablen dynamisch an den Energiezustand an.
- **Blueprint 2.0 UI-Management:** Implementiert eine stabile dynamische Konfigurationsliste mittels **RAM-Caching (Attributen)**. Dies umgeht die Puffer-Einschränkungen des Browsers und garantiert, dass Variablenauswahlen für das Dashboard niemals verloren gehen.

**3. Eingangsdaten**

- **Quelle:** Über UDP gesendete JSON-Pakete.
- **Typen:** `obs_st` (18+ Wettermetriken), `device_status` (Spannung, RSSI), `hub_status`, `rapid_wind`, `evt_precip`, `evt_strike`.
- **Konfiguration:** Benutzerdefinierter Stationsname, Profil-Präfixe, Regressionsparameter und eine koordinatenbasierte Grid-Auswahlliste.

**4. Ausgangsdaten**

- **Systemvariablen:** Strukturierte Floats, Integer und Booleans für alle Wetterdaten, technische Deltas (`time_delta`, `stamp_delta`) und Batteriekennzahlen.
- **HTML-Dashboard:** Eine responsive, rasterbasierte Visualisierung, die über einen internen Webhook (`/hook/tempest`) bereitgestellt wird.
- **Skalierung:** Nutzt CSS Container Queries (`cqi`) und `clamp()`, um Schriftarten und Layouts relativ zur Größe der HTML-Box in der Visualisierung zu skalieren.

---

### Summary of Agreed Future Changes (Highcharts)

- **Integration:** Replace or augment text values in the HTML grid with **Highcharts** sparklines.
- **Configuration:** Expand `form.json` with a "Show Chart" toggle per variable and global timeframe settings.
- **Data Source:** Use `AC_GetLoggedValues` to feed the Highcharts JS series directly from the module's backend.
- **Infrastructure:** The internal module Webhook will handle all script-less rendering.
