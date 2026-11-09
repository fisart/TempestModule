# Weatherflow Tempest — afprivat

Version **4.9.2, build 4**. This branch adds native Secrets passkey protection, separate weather and administration pages, Portal registration, debug output and module-owned configuration protection. It retains the Tempest UDP receiver, existing variable identifiers, library/module GUIDs, TMT prefix and archive backfill behavior.

## Install and open

1. In Symcon Module Control, select the **afprivat** branch of `https://github.com/fisart/TempestModule.git`, then update the library. Existing Tempest instances keep their object IDs and configured weather path. New instances default to `/hook/tempest_<instanceID>`.
2. Select the **new native Secrets backend** under General Settings → Native Secrets backend. With exactly one enabled, correctly linked backend/frontend pair, selection is automatic. The backend must provide `SECB_IsNativePortalAuthenticated` and `SECB_GetNativePortalLoginURL`. Its frontend must point back to that backend with the same trusted AdminOrigin. A migrated legacy adapter can resolve its ready native-only replacement; old vault login and Basic authentication are never used.
3. Enable protected web pages and Apply. Hook/Portal registration runs after Symcon is ready. Open the paths in the child **WebLinks** variable using the native Secrets HTTPS address. Anonymous page requests go to the native frontend passkey login; all API and chart requests recheck the native session and transport. Approved backup-address validation is delegated to the native backend.
4. Portal receives **Smart Home → Weather — [station]** and **Administration → Tempest administration — [station]** through Additional Pages. Enable “Include administration” in Portal to see its administration group. If several Portal or Dokumentation instances exist, select the intended instance in the Tempest form. Pending Portal edits and conflicting webhook ownership are preserved; resolve the status message and use **Register pages and Portal links** again.
5. Update Dokumentation to **1.34, build 66**. Its **Smart Home → Weatherflow Tempest — afprivat** guide covers installation, permissions, display, diagnostics and restore. Both Tempest pages include Portal and Dokumentation links when those instances are available.

## Weather page

The responsive, self-contained page displays the selected weather variables with their existing Symcon profile formatting/units, update time and a stale indication after ten minutes without an update. Dashboard rows are ordered by configured row and column. Missing values are explicitly unavailable. Enable charts for selected variables and select Archive Control. Charts use existing standard logged data; the administration logging button enables future recording explicitly, rather than inventing historical observations.

Each selected chart has a small plot and an expandable chart with pointer/touch value tooltips. Data are **hourly means**, with a selectable 1–168-hour window and at most 200 records per chart. Both server and browser cache history for five minutes. Arbitrary variable IDs cannot be requested; only enabled, selected, locally owned numeric variables with standard archive aggregation are eligible.

The browser polls compact JSON while visible. Default interval is ten seconds; a configured nonzero HTMLUpdateInterval uses at least two seconds on the page. Unchanged replies omit observation values. No full HTML is transferred on refresh. Requests have a generous 90-second timeout, never retry writes automatically, and failed weather reads back off to 30 seconds. Charts refresh independently of current values. No third-party libraries or CDN calls are needed by the two new pages. The existing HTMLBox Dashboard remains available and retains its historical Highcharts dependencies.

## Administration page and permissions

The separate page is `/hook/tempest_admin_<instanceID>`. It always requires a valid native Secrets application session. **Allow web administration** is off by default: the page permits inspection and credential-free configuration export only. Enable that console switch to delegate weather settings, archive logging, Portal registration and import to authenticated native Secrets application sessions. This is an explicit instance-wide permission, not a separate vault administrator-role claim.

Settings include station name, Archive Control, battery regression, weather groups and message types, display colours, font/chart sizing, timezone, refresh/time window, selected observations and grid ordering. Native Secrets binding, integration IDs, profile prefix, webhook path and the permission switch remain console-only. Writes require same-origin HTTPS validation and a short-lived CSRF token bound to the current native session cookie. Settings use a revision check to reject overwriting another editor's changes. Pending console edits are never overwritten by web mutations. If a write times out, refresh settings/status before repeating it.

**DebugEnabled** writes a bounded 32,000-byte Debug log string variable below the instance and native debug events. **WebStatus** reports setup failures; **WebLinks** holds both paths. Cookies, passkeys and CSRF/session material are excluded from debug output and exports. Debug is off by default.

## Export, import and Configuration Backup

The console and administration page both offer versioned `Tempest.afprivat.v1` configuration export/import. Exports include saved properties and dashboard selections, rather than unsaved console-list buffers. They clear inactive legacy Basic-auth credentials and contain no native vault secrets, session cookies, CSRF key, weather readings, archives or debug history.

Import validates the schema, module GUID, types, numeric limits, timezone, local hook syntax, known unique dashboard identifiers and list bounds before applying. Import always disables **WebEnabled** and **AllowWebAdministration**. Review the selected native Secrets backend and local instance/object IDs in the console, then enable the pages again. The web importer preserves the current native Secrets binding, Portal/Dokumentation selections and weather path. Console import can restore these fields for deliberate migration. Importing also replaces the pending dashboard editor buffer, preventing an old editor cache from overwriting restored selections.

The current Configuration Backup module automatically discovers `TMT_ExportConfigurationBackup` and `TMT_ValidateConfigurationBackup` through its existing **Symcon.ConfigurationBackup.v1** protocol. Include the Tempest instance under the backup server's ordinary instance-selection rules. The envelope verifies the source hash against the actual saved properties, includes the native payload, and runs importer-format validation. No hard-coded backup adapter or backup-server source change is required.

Version 4.9.2 fixes configuration-backup checksum compatibility for whole-number floating-point settings such as `HTMLFontSizeValue = 4.0`. Canonical source/payload hashes use the central backup protocol’s number encoding, and native, web and backup exports preserve floating-point types. Earlier versions could report an unsaved Tempest configuration repeatedly until the backup timed out. Update the **afprivat** library and Apply the existing Tempest instance; the backup server requires no update for this fix.

Restore mode is manual native import: pass the envelope's **payload** JSON to `TMT_ImportConfig(instanceID, payloadJSON)`, or import an ordinary ExportConfig document from the console. A valid format report is not an executed live restore test. The supplemental pending editor cache is unnecessary because the native import rebuilds it from the saved layout. Receiving station packets, archive data, native vault storage and passkeys remain covered by their existing separate backups.

## Migration notes

This branch deliberately removes unauthenticated, Basic and old vault authentication from the webhook entry point. Existing AuthMode values cannot bypass the native passkey requirement. Retained legacy credential properties are ignored and exports/imports clear them. Existing plaintext values, if any, are not automatically rewritten merely by updating code; remove them in the console or reimport a clean export when convenient.

Setup uses the correct `IPS_KERNELMESSAGE` notification with READY in `Data[0]`, plus a readiness-guarded post-apply timer. It does not change another module inside ApplyChanges. Conflicting hooks retry without taking ownership. Portal setup is idempotent and reports pending/conflicting edits in WebStatus. The historical battery profile's decimal association keys were corrected to strings so PHP preserves all seven voltage thresholds instead of collapsing them to integer key 2.

## Verification

```sh
php tests/system_portal_navigation.php
php tests/private-web.php
TEMPEST_NO_NATIVE=1 php tests/private-web.php
node tests/private-web-ui.cjs
```

The browser check requires Playwright/Chromium; optional `PHP_BIN` and `CHROMIUM_PATH` select installed binaries. Backend fixtures exercise actual module authentication, origin validation, administration permission, CSRF/session binding, optimistic revision handling, hook/Portal ownership, native backup/import safety, the real UDP observation path, unit conversion and out-of-order archive backfill. Browser fixtures exercise desktop/mobile layout, the real nonce CSP, polling, tooltips, save/export, read-only mode, import lockout and expired sessions. No live vault or weather station is contacted. Live passkey login, local archive availability and backup capture should be verified after updating the Symcon installation.

Portal navigation uses `/hook/portal`. Update/apply Portal 1.7 first; it also retains the old instance-specific address for existing bookmarks.

