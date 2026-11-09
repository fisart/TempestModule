# Tempest module development

- On afprivat, follow the canonical checksum contract in https://github.com/fisart/Symcon-Backup-Module/blob/main/.docs/configuration-backup-requirements.md before changing configuration export, import, validation or web downloads.
- Canonical source/payload JSON requires JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_PRESERVE_ZERO_FRACTION and JSON_THROW_ON_ERROR. Preserve numeric types through every configuration JSON boundary. Hash raw source properties and the exact received validator payload, not normalized replacements.
- Before publication, run the existing regression against actual independent MCBBackupProtocol/MCBEngine code using TEMPEST_BACKUP_PROTOCOL and TEMPEST_BACKUP_ENGINE. Preserve whole-number floats in IPS_GetConfiguration test fixtures. Verify genuine saved-property differences still fail. Self-comparison against Tempest's own helper is insufficient.
- Keep export/validation read-only and retain the native-import safeguards. A passing configuration validator is not an actual restore test.
- Run PHP syntax, tests/private-web.php, TEMPEST_NO_NATIVE=1 php tests/private-web.php and tests/system_portal_navigation.php. The configured GitHub workflow also checks browser behavior.
