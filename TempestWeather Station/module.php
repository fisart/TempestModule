<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RegressionHelper.php';

/**
 * TempestWeatherStation Class
 * Handles data from Weatherflow Tempest via UDP.
 */
class TempestWeatherStation extends IPSModule
{
    use \MachineLearning\Regression\RegressionHelper;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // General Settings
        $this->RegisterPropertyString('StationName', 'Tempest Station Status');
        $this->RegisterPropertyString('ProfilePrefix', 'Tempest_');
        $this->RegisterPropertyInteger('SecretsInstanceID', 0);
        $this->RegisterPropertyInteger('ArchiveID', 0);

        // Battery Regression
        $this->RegisterPropertyBoolean('ExperimentalRegression', true);
        $this->RegisterPropertyString('TriggerValueSlope', '0.000004');
        $this->RegisterPropertyInteger('RegressionDataPoints', 45);

        $this->RegisterPropertyInteger('AuthMode', 0);
        $this->RegisterPropertyString('WebhookUser', '');
        $this->RegisterPropertyString('WebhookPassword', '');

        // Visualization & Dashboard
        $this->RegisterPropertyBoolean('EnableHTML', true);
        $this->RegisterPropertyInteger('HTMLUpdateInterval', 0);
        $this->RegisterPropertyInteger('HTMLBackgroundColor', 0x222222);
        $this->RegisterPropertyInteger('HTMLFontColor', 0xFFFFFF);
        $this->RegisterPropertyInteger('ChartTimeframe', 24);
        $this->RegisterPropertyInteger('ChartColor', 0xFFFFFF);
        $this->RegisterPropertyString('HTMLVariableList', json_encode([
            ['Label' => 'Temperature', 'Show' => true, 'Row' => 1, 'Col' => 1, 'Ident' => 'Air_Temperature'],
            ['Label' => 'Humidity', 'Show' => true, 'Row' => 1, 'Col' => 2, 'Ident' => 'Relative_Humidity'],
            ['Label' => 'Pressure', 'Show' => true, 'Row' => 2, 'Col' => 1, 'Ident' => 'Station_Pressure'],
            ['Label' => 'Wind Avg', 'Show' => true, 'Row' => 2, 'Col' => 2, 'Ident' => 'Wind_Avg'],
            ['Label' => 'Battery', 'Show' => true, 'Row' => 3, 'Col' => 1, 'Ident' => 'Battery'],
            ['Label' => 'Solar Rad.', 'Show' => true, 'Row' => 3, 'Col' => 2, 'Ident' => 'Solar_Radiation']
        ]));

        // RAM-Caching via Attribute (Blueprint Strategy Section 3, Step 2)
        $this->RegisterAttributeString('HTMLVariableListBuffer', '[]');

        $this->RegisterVariableString('Dashboard', 'Dashboard', '~HTMLBox', 150);

        // Register Timer for Dashboard Refresh
        $this->RegisterTimer('UpdateDashboardTimer', 0, 'TMT_UpdateDashboard($_IPS[\'TARGET\']);');

        // Granular Selectors
        $this->RegisterPropertyBoolean('Var_Wind', true);
        $this->RegisterPropertyBoolean('Var_Air', true);
        $this->RegisterPropertyBoolean('Var_Light', true);
        $this->RegisterPropertyBoolean('Var_Precip', true);
        $this->RegisterPropertyBoolean('Var_Lightning', true);
        $this->RegisterPropertyBoolean('Var_System', true);

        // Message Type Toggles
        $this->RegisterPropertyBoolean('MsgObsSt', true);
        $this->RegisterPropertyBoolean('MsgDeviceStatus', true);
        $this->RegisterPropertyBoolean('MsgHubStatus', true);
        $this->RegisterPropertyBoolean('MsgRapidWind', true);
        $this->RegisterPropertyBoolean('MsgPrecip', true);
        $this->RegisterPropertyBoolean('MsgStrike', true);

        $this->RegisterPropertyString('WebhookPath', '/hook/tempest');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->UpdateProfiles();

        // Blueprint Strategy 2.0 - Schritt 4: Label-Heilung / Persistence Guard
        // Fixes corruption caused by the browser omitting readOnly columns during standard "Apply"
        $buffer = $this->ReadAttributeString('HTMLVariableListBuffer');
        $property = $this->ReadPropertyString('HTMLVariableList');
        $propArr = json_decode($property, true);

        // If the Property is corrupted (missing Idents) but the RAM-Cache is healthy, restore it
        if (isset($propArr[0]) && !isset($propArr[0]['Ident']) && $buffer !== '[]' && $buffer !== '') {
            IPS_SetProperty($this->InstanceID, 'HTMLVariableList', $buffer);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->UpdateUI();

        // Register for Kernel Started message
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Set Receive Filter for supported Tempest UDP packet types (Rule 4.1)
        $this->SetReceiveDataFilter('.*(obs_st|device_status|hub_status|rapid_wind|evt_precip|evt_strike).*');

        // Manage Dashboard Timer
        $interval = $this->ReadPropertyBoolean('EnableHTML') ? $this->ReadPropertyInteger('HTMLUpdateInterval') : 0;
        $this->SetTimerInterval('UpdateDashboardTimer', $interval * 1000);

        // Only proceed with instance-dependent logic if Kernel is ready
        if (IPS_GetKernelRunlevel() == 10103) {
            $this->RegisterHook($this->ReadPropertyString('WebhookPath'));
            $this->GenerateHTMLDashboard();
        }
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $controlID = $ids[0];
            $hooks = json_decode(IPS_GetProperty($controlID, 'Hooks'), true);
            if (!is_array($hooks)) $hooks = [];

            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
            }

            IPS_SetProperty($controlID, 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($controlID);
            $this->LogMessage("Webhook registered: " . $WebHook, KL_MESSAGE);
        } else {
            $this->LogMessage("Error: WebHook Control not found", KL_ERROR);
        }
    }

    /**
     * This function is called by the Webhook Bot
     */
    protected function ProcessHookData()
    {
        // 1. Web App Manifest Handler
        if (isset($_GET['manifest'])) {
            header('Content-Type: application/json');
            $bgColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLBackgroundColor'));
            echo json_encode([
                "name" => $this->ReadPropertyString('StationName'),
                "short_name" => "Tempest",
                "start_url" => ".",
                "display" => "standalone",
                "background_color" => $bgColor,
                "theme_color" => $bgColor,
                "icons" => [
                    [
                        "src" => "https://weatherflow.github.io/Tempest/img/tempest-icon-192.png",
                        "sizes" => "192x192",
                        "type" => "image/png"
                    ]
                ]
            ]);
            return;
        }

        // 2. Authentication Validation
        $authMode = $this->ReadPropertyInteger('AuthMode');
        $validUser = '';
        $validPass = '';
        $secretsID = $this->ReadPropertyInteger('SecretsInstanceID');

        if ($authMode === 1) { // Manual
            $validUser = $this->ReadPropertyString('WebhookUser');
            $validPass = $this->ReadPropertyString('WebhookPassword');
        } elseif ($authMode === 2) { // Secrets Manager (User/PW)
            if ($secretsID > 0 && IPS_InstanceExists($secretsID)) {
                $access_data = json_decode(SEC_GetSecret($secretsID, 'Webhooks'), true);
                $validUser = $access_data['Tempest']['User'] ?? '';
                $validPass = $access_data['Tempest']['PW'] ?? '';
            }
        } elseif ($authMode === 3) { // Passkey (Biometric)
            if ($secretsID > 0 && IPS_InstanceExists($secretsID)) {
                if (!SEC_IsPortalAuthenticated($secretsID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    $loginUrl = "/hook/secrets_" . $secretsID . "?portal=1&return=" . urlencode($currentUrl);
                    header("Location: " . $loginUrl);
                    exit;
                }
            }
        }

        // Only check Basic Auth for Manual or standard Secrets Manager modes
        if ($authMode === 1 || $authMode === 2) {
            if (!isset($_SERVER['PHP_AUTH_USER'])) $_SERVER['PHP_AUTH_USER'] = "";
            if (!isset($_SERVER['PHP_AUTH_PW'])) $_SERVER['PHP_AUTH_PW'] = "";

            if (($_SERVER['PHP_AUTH_USER'] !== $validUser) || ($_SERVER['PHP_AUTH_PW'] !== $validPass)) {
                header('WWW-Authenticate: Basic Realm="Tempest Dashboard"');
                header('HTTP/1.0 401 Unauthorized');
                echo "Authorization required";
                return;
            }
        }

        // 3. Render Dashboard with Standalone App wrapper
        $dashboardHTML = $this->GetValue('Dashboard');
        $bgColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLBackgroundColor'));
        $title = $this->ReadPropertyString('StationName');

        echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'>
    <title>$title</title>
    <link rel='manifest' href='?manifest=1'>
    <link rel='apple-touch-icon' href='https://weatherflow.github.io/Tempest/img/tempest-icon-192.png'>
    <meta name='theme-color' content='$bgColor'>
    <meta name='apple-mobile-web-app-capable' content='yes'>
    <meta name='apple-mobile-web-app-status-bar-style' content='black-translucent'>
    <meta name='mobile-web-app-capable' content='yes'>
    <meta name='apple-mobile-web-app-title' content='Tempest'>
    <style>
        body, html { margin: 0; padding: 0; width: 100vw; height: 100vh; overflow: hidden; background-color: $bgColor; }
        #container { width: 100%; height: 100%; }
    </style>
</head>
<body>
    <div id='container'>
        $dashboardHTML
    </div>
</body>
</html>";
    }


    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!$data) return;

        $payload = json_decode($data['Buffer'], true);
        if (!$payload || !isset($payload['type'])) return;

        $this->SendDebug('ReceiveData', $data['Buffer'], 0);

        switch ($payload['type']) {
            case 'obs_st':
                if ($this->ReadPropertyBoolean('MsgObsSt')) $this->ProcessObservation($payload);
                break;
            case 'device_status':
                if ($this->ReadPropertyBoolean('MsgDeviceStatus')) $this->ProcessDeviceStatus($payload);
                break;
            case 'hub_status':
                if ($this->ReadPropertyBoolean('MsgHubStatus')) $this->ProcessHubStatus($payload);
                break;
            case 'rapid_wind':
                if ($this->ReadPropertyBoolean('MsgRapidWind')) $this->ProcessRapidWind($payload);
                break;
            case 'evt_precip':
                if ($this->ReadPropertyBoolean('MsgPrecip')) $this->ProcessPrecip($payload);
                break;
            case 'evt_strike':
                if ($this->ReadPropertyBoolean('MsgStrike')) $this->ProcessStrike($payload);
                break;
        }

        $this->GenerateHTMLDashboard();
    }

    private function ProcessObservation(array $data)
    {
        $obs = $data['obs'][0];
        $timestamp = $obs[0];

        if ($obs[7] == 0 && $obs[8] == 0) {
            $this->LogMessage('Tempest: Corrupt data received (0 Temp/Hum), skipping.', 10206);
            return;
        }

        $check = $this->CheckTimestamp('Time_Epoch', $timestamp);
        if ($check === 'INVALID') return;

        $config = $this->GetModuleConfig();
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        foreach ($config['descriptions']['obs_st'] as $index => $name) {
            if ($index == 19) continue; // Skip Rohdaten

            $shouldCreate = false;
            if ($index == 0) $shouldCreate = true;
            if (in_array($index, [1, 2, 3, 4, 5]) && $this->ReadPropertyBoolean('Var_Wind')) $shouldCreate = true;
            if (in_array($index, [6, 7, 8]) && $this->ReadPropertyBoolean('Var_Air')) $shouldCreate = true;
            if (in_array($index, [9, 10, 11]) && $this->ReadPropertyBoolean('Var_Light')) $shouldCreate = true;
            if (in_array($index, [12, 13]) && $this->ReadPropertyBoolean('Var_Precip')) $shouldCreate = true;
            if (in_array($index, [14, 15]) && $this->ReadPropertyBoolean('Var_Lightning')) $shouldCreate = true;
            if (in_array($index, [16, 17]) && $this->ReadPropertyBoolean('Var_System')) $shouldCreate = true;
            if ($index >= 18) $shouldCreate = true;

            if (!$shouldCreate) continue;

            $val = $obs[$index] ?? null;
            $ident = str_replace([' ', '(', ')'], ['_', '', ''], $name);
            $profileIdent = $this->GetProfileForName($name);

            if (strpos($name, 'Wind') !== false && strpos($name, 'Direction') === false && strpos($name, 'Interval') === false) {
                $val = $val * 3.6;
            }

            if (!isset($config['profiles'][$profileIdent])) continue;

            $varType = $config['profiles'][$profileIdent]['type'];
            // Fix: Use MaintainVariableSafe for auto-correction
            $this->MaintainVariableSafe($ident, $name, $varType, $prefix . $profileIdent, $index, true);
            $this->HandleValueUpdate($ident, $val, $timestamp, $check);
        }

        // Fix: Removed redundant/conflicting hardcoded $type block
        $delta = time() - $timestamp;
        $this->HandleValueUpdate('stamp_delta', $delta, $timestamp, 'NEW_VALUE');

        if ($this->ReadPropertyBoolean('ExperimentalRegression')) {
            $this->UpdateBatteryLogic((float)$obs[16]);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->RegisterHook($this->ReadPropertyString('WebhookPath'));
                $this->GenerateHTMLDashboard();
                break;
        }
    }

    public function EnableLogging()
    {
        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        if ($archiveID === 0 || !IPS_InstanceExists($archiveID)) {
            echo "Error: Please select an Archive Control in the settings first.";
            return;
        }

        // 1. Enable for Battery (required for Regression)
        $batteryID = @$this->GetIDForIdent('Battery');
        if ($batteryID) {
            AC_SetLoggingStatus($archiveID, $batteryID, true);
        }

        // 2. Enable for Chart variables
        $varList = json_decode($this->ReadPropertyString('HTMLVariableList'), true) ?: [];
        foreach ($varList as $item) {
            if ($item['ShowChart'] ?? false) {
                $varID = @$this->GetIDForIdent($item['Ident']);
                if ($varID) {
                    AC_SetLoggingStatus($archiveID, $varID, true);
                }
            }
        }

        IPS_ApplyChanges($archiveID);
        echo "Archive logging has been enabled for the Battery and all selected Chart variables.";
    }


    private function UpdateBatteryLogic(float $currentVoltage)
    {
        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        if ($archiveID === 0 || !IPS_InstanceExists($archiveID)) {
            $this->LogMessage("UpdateBatteryLogic: Archive Control not configured.", KL_WARNING);
            return;
        }

        $nrPoints = $this->ReadPropertyInteger('RegressionDataPoints');
        $triggerSlope = (float)$this->ReadPropertyString('TriggerValueSlope');
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        $batteryID = @$this->GetIDForIdent('Battery');
        if ($batteryID === 0) {
            $this->LogMessage("UpdateBatteryLogic: 'Battery' variable not found. Regression aborted.", KL_ERROR);
            return;
        }

        if (!AC_GetLoggingStatus($archiveID, $batteryID)) {
            $this->LogMessage("Battery Regression: Archive Logging is disabled for 'Battery'. No data available for calculation.", KL_WARNING);
            return;
        }

        $history = @AC_GetLoggedValues($archiveID, $batteryID, time() - 86400, time(), $nrPoints);
        if (!is_array($history)) {
            $this->LogMessage("UpdateBatteryLogic: Failed to retrieve archive data for 'Battery'.", KL_ERROR);
            return;
        }

        if (count($history) < 5) return;

        $x = [];
        $y = [];
        foreach ($history as $row) {
            $x[] = $row['TimeStamp'];
            $y[] = $row['Value'];
        }

        $regression = new \MachineLearning\Regression\LeastSquares();
        $regression->train($x, $y);
        $slope = $regression->getSlope();

        $config = $this->GetModuleConfig();

        // Maintain variables including System Condition and Counter
        $this->MaintainVariableSafe('Battery_Status', 'Battery Status', $config['profiles']['battery_status']['type'], $prefix . 'battery_status', 23, true);
        $this->MaintainVariableSafe('Slope', 'Regression Slope', $config['profiles']['slope']['type'], $prefix . 'slope', 22, true);
        $this->MaintainVariableSafe('Average', 'Average Voltage', $config['profiles']['volt']['type'], $prefix . 'volt', 20, true);
        $this->MaintainVariableSafe('Counter_Slope_Datasets', 'Counter Slope Datasets', 1, $prefix . 'seconds', 21, true);
        $this->MaintainVariableSafe('System_Condition', 'System Condition', $config['profiles']['system_condition']['type'], $prefix . 'system_condition', 24, true);

        $statusID = $this->GetIDForIdent('Battery_Status');
        $slopeID = $this->GetIDForIdent('Slope');
        $avgID = $this->GetIDForIdent('Average');
        $sysCondID = @IPS_GetObjectIDByIdent('System_Condition', $this->InstanceID);

        $oldSlope = GetValue($slopeID);
        $isCharging = (bool)GetValue($statusID);
        $newState = $isCharging ? ($slope >= $triggerSlope && $slope >= $oldSlope) : ($slope >= $triggerSlope && $slope > $oldSlope);

        // Fix: Dynamic Profile Association Swap for System Condition
        $pName = $prefix . 'system_condition';
        $associations = $newState ? $config['charge'] : $config['discharge'];
        foreach ($associations['Text'] as $val => $text) {
            IPS_SetVariableProfileAssociation($pName, (float)$val, $text, '', $associations['Color'][$val] ?? -1);
        }

        SetValue($avgID, $this->calculate_average($y));
        SetValue($this->GetIDForIdent('Counter_Slope_Datasets'), count($x));
        SetValue($slopeID, $slope);
        SetValue($statusID, (int)$newState);
        SetValue($sysCondID, $currentVoltage);
    }

    private function GenerateHTMLDashboard()
    {
        if (!$this->ReadPropertyBoolean('EnableHTML')) return;

        $stationName = $this->ReadPropertyString('StationName');
        $bgColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLBackgroundColor'));
        $fontColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLFontColor'));
        $varList = json_decode($this->ReadPropertyString('HTMLVariableList'), true) ?: [];
        $interval = $this->ReadPropertyInteger('HTMLUpdateInterval');
        $chartTimeframe = $this->ReadPropertyInteger('ChartTimeframe');
        $chartColor = sprintf("#%06X", $this->ReadPropertyInteger('ChartColor'));

        $timeID = @IPS_GetObjectIDByIdent('Time_Epoch', $this->InstanceID);
        $timeStr = ($timeID !== 0 && IPS_VariableExists($timeID)) ? date('H:i:s', GetValue($timeID)) : '--:--:--';

        $sysCondID = @IPS_GetObjectIDByIdent('System_Condition', $this->InstanceID);
        $sysCondStr = ($sysCondID !== 0 && IPS_VariableExists($sysCondID)) ? GetValueFormatted($sysCondID) : '';

        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        $archiveReady = ($archiveID > 0 && IPS_InstanceExists($archiveID));

        $itemsHtml = "";
        $chartScripts = "";

        foreach ($varList as $item) {
            if (!($item['Show'] ?? false)) continue;
            $varID = @IPS_GetObjectIDByIdent($item['Ident'], $this->InstanceID);
            if ($varID === 0 || !IPS_VariableExists($varID)) {
                if ($varID === 0) {
                    $this->LogMessage("GenerateHTMLDashboard: Ident '" . $item['Ident'] . "' not found.", KL_WARNING);
                }
                continue;
            }

            $formatted = GetValueFormatted($varID);
            $label = $item['Label'] ?? $item['Ident'] ?? 'Unknown';
            $chartHtml = "";

            if (($item['ShowChart'] ?? false) && $archiveReady) {
                if (AC_GetLoggingStatus($archiveID, $varID)) {
                    $history = AC_GetLoggedValues($archiveID, $varID, time() - ($chartTimeframe * 3600), time(), 0);
                    if (is_array($history) && count($history) > 1) {
                        $points = [];
                        $chartType = 'area';

                        // Special Logic for Wind Barbs (Meteorological Standard)
                        if ($item['Ident'] === 'Wind_Direction') {
                            $speedID = @IPS_GetObjectIDByIdent('Wind_Avg', $this->InstanceID);
                            if ($speedID && AC_GetLoggingStatus($archiveID, $speedID)) {
                                $speedHistory = AC_GetLoggedValues($archiveID, $speedID, time() - ($chartTimeframe * 3600), time(), 0);
                                $speedMap = [];
                                foreach ($speedHistory as $sRow) {
                                    $speedMap[$sRow['TimeStamp']] = $sRow['Value'];
                                }

                                $speedPoints = []; // For the Area series
                                $barbPoints = [];  // For the Arrows series
                                foreach (array_reverse($history) as $row) {
                                    if (isset($speedMap[$row['TimeStamp']])) {
                                        $sVal = round($speedMap[$row['TimeStamp']], 2);
                                        $dVal = round($row['Value'], 2);
                                        $ts = $row['TimeStamp'] * 1000;
                                        $speedPoints[] = "[$ts, $sVal]";
                                        $barbPoints[] = "[$ts, $sVal, $dVal]";
                                    }
                                }
                                $dataString = "{ type: 'area', data: [" . implode(',', $speedPoints) . "], color: '$chartColor', fillOpacity: 0.3, zIndex: 1 }, { type: 'windbarb', data: [" . implode(',', $barbPoints) . "], color: '$fontColor', zIndex: 2, vectorLength: 12, yOffset: 30 }";
                            }
                        } else {
                            foreach (array_reverse($history) as $row) {
                                $points[] = "[" . ($row['TimeStamp'] * 1000) . "," . round($row['Value'], 2) . "]";
                            }
                            $dataString = "{ type: 'area', data: [" . implode(',', $points) . "], color: '$chartColor' }";
                        }

                        $chartID = "chart_" . $item['Ident'];
                        $chartHtml = "<div id='$chartID' style='width: 100%; height: 30px; margin-top: 5px;'></div>";

                        $chartScripts .= "
                        Highcharts.chart('$chartID', {
                            chart: { margin: [2, 5, 2, 5], backgroundColor: null, height: 30, skipClone: true },
                            title: { text: null }, credits: { enabled: false }, legend: { enabled: false }, accessibility: { enabled: false },
                            xAxis: { visible: false, type: 'datetime' }, yAxis: { visible: false },
                            tooltip: { enabled: true, headerFormat: '', pointFormat: '{point.x:%H:%M}: <b>{point.y}</b>', outside: true },
                            plotOptions: { 
                                series: { marker: { enabled: false }, lineWidth: 1, animation: false },
                                area: { fillOpacity: 0.1 }
                            },
                            series: [$dataString]
                        });";
                    }
                } else {
                    $chartHtml = "<div style='font-size: 8px; opacity: 0.5; margin-top: 5px;'>(Logging Off)</div>";
                }
            }

            $itemsHtml .= "
            <div style='grid-area: {$item['Row']} / {$item['Col']}; border: 1px solid rgba(255,255,255,0.1); padding: 1cqi; text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; border-radius: 4px; overflow: hidden;'>
                <div style='font-size: clamp(8px, 2.2cqi, 18px); opacity: 0.7; white-space: nowrap; text-overflow: ellipsis; width: 100%; overflow: hidden;'>$label</div>
                <div style='font-size: clamp(10px, 4.5cqi, 36px); font-weight: bold; white-space: nowrap;'>$formatted</div>
                $chartHtml
            </div>";
        }

        $reloadScript = ($interval > 0) ? "<script>setTimeout(function(){ location.reload(); }, " . (($interval + 2) * 1000) . ");</script>" : "";
        $highChartsScript = "<script src='https://code.highcharts.com/highcharts.js'></script><script src='https://code.highcharts.com/highcharts-more.js'></script><script src='https://code.highcharts.com/modules/datagrouping.js'></script><script src='https://code.highcharts.com/modules/windbarb.js'></script>";

        $html = "
        <div style='container-type: inline-size; background-color: $bgColor; color: $fontColor; font-family: \"Segoe UI\", sans-serif; height: 100%; width: 100%; box-sizing: border-box; display: flex; flex-direction: column; padding: 1.5cqi; border-radius: 8px;'>
            $highChartsScript
            <div style='text-align: center; font-size: clamp(12px, 5cqi, 48px); font-weight: bold; padding-bottom: 1.5cqi; border-bottom: 1px solid rgba(255,255,255,0.2);'>
                $stationName <span style='font-size: 0.6em; opacity: 0.6; margin-left: 2cqi;'>($timeStr)</span>
                <div style='font-size: 0.4em; opacity: 0.8; font-weight: normal; margin-top: 0.5cqi;'>$sysCondStr</div>
            </div>
            <div style='display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: 1fr; gap: 1cqi; flex-grow: 1; margin-top: 1.5cqi;'>
                $itemsHtml
            </div>
 <script>
                (function() {
                    function initCharts() {
                        if (typeof Highcharts === 'undefined' || typeof Highcharts.seriesTypes.windbarb === 'undefined') {
                            setTimeout(initCharts, 50);
                            return;
                        }
                        $chartScripts
                    }
                    initCharts();
                })();
            </script>
            $reloadScript
        </div>";

        $this->SetValue('Dashboard', $html);
    }

    private function HandleValueUpdate(string $ident, $value, int $timestamp, string $check)
    {
        if ($value === null) return;
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false || $varID === 0 || !IPS_VariableExists($varID)) {
            $this->LogMessage("HandleValueUpdate: Variable Ident '" . $ident . "' not found.", KL_WARNING);
            return;
        }

        $type = IPS_GetVariable($varID)['VariableType'];
        switch ($type) {
            case 0:
                $value = (bool)$value;
                break;
            case 1:
                $value = (int)$value;
                break;
            case 2:
                $value = (float)$value;
                break;
            case 3:
                $value = is_array($value) ? json_encode($value) : (string)$value;
                break;
        }

        if ($check === 'OLD_TIME_STAMP') {
            $archiveID = $this->ReadPropertyInteger('ArchiveID');
            if ($archiveID > 0 && IPS_InstanceExists($archiveID)) {
                AC_AddLoggedValues($archiveID, $varID, [['TimeStamp' => $timestamp, 'Value' => $value]]);
                AC_ReAggregateVariable($archiveID, $varID);
            }
        } else {
            SetValue($varID, $value);
        }
    }

    private function ProcessDeviceStatus(array $data)
    {
        $timestamp = $data['timestamp'];
        if ($this->CheckTimestamp('timestamp_device', $timestamp) === 'INVALID') return;

        $config = $this->GetModuleConfig();
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        foreach ($config['descriptions']['device_status'] as $index => $name) {
            if (!isset($data[$name]) || is_array($data[$name])) continue;
            $val = $data[$name];
            $ident = 'dev_' . str_replace(' ', '_', $name);

            if ($name == 'sensor_status') {
                // Power-Booster Logik (Bits 10, 11, 16, 17)
                $this->MaintainVariableSafe($ident . '_bit10', 'Booster-Status (Bit 10)', 0, '', $index + 68, true);
                $this->HandleValueUpdate($ident . '_bit10', ($val & 0x200) > 0, $timestamp, 'NEW_VALUE');

                $this->MaintainVariableSafe($ident . '_bit11', 'Booster-Status (Bit 11)', 0, '', $index + 69, true);
                $this->HandleValueUpdate($ident . '_bit11', ($val & 0x400) > 0, $timestamp, 'NEW_VALUE');

                $this->MaintainVariableSafe($ident . '_booster_empty', 'Power-Booster erschöpft', 0, '', $index + 70, true);
                $this->HandleValueUpdate($ident . '_booster_empty', ($val & 0x8000) > 0, $timestamp, 'NEW_VALUE');

                $this->MaintainVariableSafe($ident . '_booster_connected', 'Power-Booster extern', 0, '', $index + 71, true);
                $this->HandleValueUpdate($ident . '_booster_connected', ($val & 0x10000) > 0, $timestamp, 'NEW_VALUE');

                // Maske auf 17 Bits erweitert (0x1FFFF), um alle relevanten Bits im Hauptwert zu behalten
                $val = $val & 0x1FFFF;
            }

            $profileIdent = $this->GetProfileForName($name);
            // Fix: Use dynamic type lookup and safe maintenance to force correct variable type
            $varType = $config['profiles'][$profileIdent]['type'] ?? 3;
            $this->MaintainVariableSafe($ident, $name, $varType, $prefix . $profileIdent, $index + 50, true);
            $this->HandleValueUpdate($ident, $val, $timestamp, 'NEW_VALUE');
        }
    }

    private function ProcessHubStatus(array $data)
    {
        if (!isset($data['timestamp'])) return;
        $timestamp = $data['timestamp'];
        if ($this->CheckTimestamp('timestamp_hub', $timestamp) === 'INVALID') return;

        $config = $this->GetModuleConfig();
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        foreach ($config['descriptions']['hub_status'] as $index => $name) {
            if ($name === null || !isset($data[$name])) continue;
            $val = $data[$name];

            if ($name == 'radio_stats' && is_array($val)) {
                foreach ($val as $subIndex => $subVal) {
                    if (!isset($config['descriptions']['radio_stats'][$subIndex])) continue;
                    $subName = $config['descriptions']['radio_stats'][$subIndex];
                    $subProfile = $this->GetProfileForName($subName);
                    $this->MaintainVariable('hub_radio_' . str_replace(' ', '_', $subName), $subName, $config['profiles'][$subProfile]['type'], $prefix . $subProfile, $subIndex + 100, true);
                    $this->HandleValueUpdate('hub_radio_' . str_replace(' ', '_', $subName), $subVal, $timestamp, 'NEW_VALUE');
                }
            } else {
                if (is_array($val)) continue;
                $profile = $this->GetProfileForName($name);
                $this->MaintainVariable('hub_' . str_replace(' ', '_', $name), $name, $config['profiles'][$profile]['type'], $prefix . $profile, $index + 80, true);
                $this->HandleValueUpdate('hub_' . str_replace(' ', '_', $name), $val, $timestamp, 'NEW_VALUE');
            }
        }
    }

    private function ProcessRapidWind(array $data)
    {
        $timestamp = $data['ob'][0];
        if ($this->CheckTimestamp('Time_Epoch_Wind', $timestamp) === 'INVALID') return;
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        $this->MaintainVariable('Wind_Speed', 'Wind Speed', 2, $prefix . 'km_pro_stunde', 10, true);
        $this->HandleValueUpdate('Wind_Speed', $data['ob'][1] * 3.6, $timestamp, 'NEW_VALUE');

        $this->MaintainVariable('Wind_Direction_Rapid', 'Wind Direction (Rapid)', 2, $prefix . 'wind_direction', 11, true);
        $this->HandleValueUpdate('Wind_Direction_Rapid', $data['ob'][2], $timestamp, 'NEW_VALUE');
    }

    private function ProcessPrecip(array $data)
    {
        $timestamp = $data['evt'][0];
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        $this->MaintainVariable('Rain_Start_Event', 'Rain Start Event', 1, $prefix . 'UnixTimestamp', 120, true);
        $this->HandleValueUpdate('Rain_Start_Event', $timestamp, $timestamp, 'NEW_VALUE');
    }

    private function ProcessStrike(array $data)
    {
        $timestamp = $data['evt'][0];
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        $this->MaintainVariable('Strike_Distance', 'Distance', 2, $prefix . 'km', 130, true);
        $this->HandleValueUpdate('Strike_Distance', $data['evt'][1], $timestamp, 'NEW_VALUE');
        $this->MaintainVariable('Strike_Energy', 'Energy', 1, $prefix . 'energy', 131, true);
        $this->HandleValueUpdate('Strike_Energy', $data['evt'][2], $timestamp, 'NEW_VALUE');
    }

    private function CheckTimestamp(string $ident, int $timestamp)
    {
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false || $varID === 0 || !IPS_VariableExists($varID)) {
            return 'NEW_VALUE';
        }

        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        if ($archiveID === 0 || !IPS_InstanceExists($archiveID)) return 'NEW_VALUE';

        if (!AC_GetLoggingStatus($archiveID, $varID)) return 'NEW_VALUE';
        if ($timestamp > time()) return 'INVALID';

        $lastValues = AC_GetLoggedValues($archiveID, $varID, $timestamp - 1, $timestamp + 1, 1);
        if (!empty($lastValues) && $lastValues[0]['Value'] == $timestamp) return 'INVALID';

        return ($timestamp < IPS_GetVariable($varID)['VariableUpdated']) ? 'OLD_TIME_STAMP' : 'NEW_VALUE';
    }

    public function UpdateProfiles()
    {
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        if ($prefix !== '' && substr($prefix, -1) !== '.' && substr($prefix, -1) !== '_') {
            $prefix .= '.';
        }

        $config = $this->GetModuleConfig();
        foreach ($config['profiles'] as $name => $p) {
            $this->RegisterProfile($prefix . $name, $p['type'], $p['digits'], $p['prefix'], $p['suffix'], $p['min'], $p['max'], $p['step'], $p['associations'] ?? null);
        }
    }

    private function GetModuleConfig()
    {
        $green = 0x006400;
        $red = 0xFF0000;
        $blue = 0x0000FF;
        $yellow = 0xCBCF00;
        $purple = 0x800080;
        $orange = 0xFFA500;
        return [
            'descriptions' => [
                'obs_st' => [0 => 'Time Epoch', 1 => 'Wind Lull', 2 => 'Wind Avg', 3 => 'Wind Gust', 4 => 'Wind Direction', 5 => 'Wind Sample Interval', 6 => 'Station Pressure', 7 => 'Air Temperature', 8 => 'Relative Humidity', 9 => 'Illuminance', 10 => 'UV', 11 => 'Solar Radiation', 12 => 'Precip Accumulated', 13 => 'Precipitation Type', 14 => 'Lightning Strike Avg Distance', 15 => 'Lightning Strike Count', 16 => 'Battery', 17 => 'Report Interval', 18 => 'Slope', 20 => 'Battery Status', 21 => 'System Condition', 22 => 'Counter Slope Datasets', 23 => 'Average', 24 => 'Median', 25 => 'time_delta', 26 => 'stamp_delta'],
                'device_status' => [0 => 'serial_number', 1 => 'type', 2 => 'hub_sn', 3 => 'timestamp', 4 => 'uptime', 5 => 'voltage', 6 => 'firmware_revision', 7 => 'rssi', 8 => 'hub_rssi', 9 => 'sensor_status', 10 => 'debug', 12 => 'time_delta'],
                'hub_status' => [0 => 'serial_number', 1 => 'type', 2 => 'firmware_revision', 3 => 'uptime', 4 => 'rssi', 5 => 'timestamp', 6 => 'reset_flags', 7 => 'seq', 9 => 'radio_stats', 17 => 'time_delta'],
                'radio_stats' => [0 => 'Version', 1 => 'Reboot Count', 2 => 'I2C Bus Error Count', 3 => 'Radio Status', 4 => 'Radio Network ID']
            ],
            'profiles' => [
                'km_pro_stunde' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' km/h', 'min' => 0, 'max' => 160, 'step' => 0.01],
                'celcius' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' °C', 'min' => -40, 'max' => 45, 'step' => 0.01],
                'volt' => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => ' V', 'min' => 0, 'max' => 4, 'step' => 0.001],
                'percent' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' %', 'min' => 0, 'max' => 100, 'step' => 0.01],
                'milli_bar' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' hPa', 'min' => 0, 'max' => 9999, 'step' => 1],
                'wind_direction' => ['type' => 2, 'digits' => 1, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 360, 'step' => 1],
                'energy' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' W/m²', 'min' => 0, 'max' => 1000, 'step' => 1],
                'lux' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' Lx', 'min' => 0, 'max' => 120000, 'step' => 1],
                'index' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' UVI', 'min' => 0, 'max' => 15, 'step' => 0.01],
                'mm' => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => ' mm', 'min' => 0, 'max' => 100, 'step' => 0.000001],
                'km' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' km', 'min' => 0, 'max' => 100, 'step' => 0.01],
                'rssi' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' dB', 'min' => -100, 'max' => 0, 'step' => 1],
                'seconds' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' s', 'min' => 0, 'max' => 999999999, 'step' => 1],
                'minutes' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' min', 'min' => 0, 'max' => 60, 'step' => 1],
                'UnixTimestamp' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 999999999, 'step' => 1],
                'slope' => ['type' => 2, 'digits' => 9, 'prefix' => '', 'suffix' => ' mx+b', 'min' => -10, 'max' => 10, 'step' => 0.00000001],
                'battery_status' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 1, 'step' => 0, 'associations' => ['Text' => [0 => 'Discharge', 1 => 'Charge'], 'Color' => [0 => $red, 1 => $green]]],
                'perception_type' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 2, 'step' => 1, 'associations' => ['Text' => [0 => 'none', 1 => 'rain', 2 => 'hail'], 'Color' => [0 => $green, 1 => $blue, 2 => $red]]],
                'Radio_Status' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 3, 'step' => 1, 'associations' => ['Text' => [0 => 'Off', 1 => 'On', 3 => 'Active'], 'Color' => [0 => $red, 1 => $blue, 3 => $green]]],
                'system_condition' => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 3, 'step' => 0.001, 'associations' => null],
                // Fix: Re-inserting the missing 'status' profile key
                'status' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 200000, 'step' => 1, 'associations' => ['Text' => [0 => 'Sensors OK', 511 => 'Multiple Failures'], 'Color' => [0 => $green, 511 => $red]]],
                'count'  => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 1000000, 'step' => 1],
                'text'   => ['type' => 3, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 0, 'step' => 0]
            ],
            'charge' => ['Text' => [2.33 => '+ Hybernate', 2.355 => '+ Wind 5 Min NO Lightning+Rain', 2.375 => '+ Wind 1m', 2.39 => '+ Wind 1m', 2.41 => '+ Wind 6s', 2.415 => '+ Wind 6s', 2.455 => '+ Full Perf'], 'Color' => [2.33 => $purple, 2.355 => $red, 2.375 => $orange, 2.39 => $orange, 2.41 => $yellow, 2.415 => $yellow, 2.455 => $green]],
            'discharge' => ['Text' => [2.33 => '- Hybernate', 2.355 => '- Wind 5 Min NO Lightning+Rain', 2.375 => '- Wind 1m', 2.39 => '- Wind 1m', 2.41 => '- Wind 6s', 2.415 => '- Wind 6s', 2.455 => '- Full Perf'], 'Color' => [2.33 => $purple, 2.355 => $red, 2.375 => $orange, 2.39 => $orange, 2.41 => $yellow, 2.415 => $yellow, 2.455 => $green]]
        ];
    }

    private function GetProfileForName(string $name)
    {
        $mapping = [
            'Air Temperature'               => 'celcius',
            'Relative Humidity'             => 'percent',
            'Wind Avg'                      => 'km_pro_stunde',
            'Wind Lull'                     => 'km_pro_stunde',
            'Wind Gust'                     => 'km_pro_stunde',
            'Wind Speed'                    => 'km_pro_stunde',
            'Battery'                       => 'volt',
            'voltage'                       => 'volt',
            'Average'                       => 'volt',
            'Median'                        => 'volt',
            'Average Voltage'               => 'volt',
            'Median Voltage'                => 'volt',
            'Time Epoch'                    => 'UnixTimestamp',
            'timestamp'                     => 'UnixTimestamp',
            'Rain Start Event'              => 'UnixTimestamp',
            'Station Pressure'              => 'milli_bar',
            'Wind Direction'                => 'wind_direction',
            'Illuminance'                   => 'lux',
            'UV'                            => 'index',
            'Solar Radiation'               => 'energy',
            'Energy'                        => 'energy',
            'Precip Accumulated'            => 'mm',
            'Precipitation Type'            => 'perception_type',
            'Lightning Strike Avg Distance' => 'km',

            'Lightning Strike Count'        => 'count',
            'Counter Slope Datasets'        => 'count',
            'Distance'                      => 'km',
            'Report Interval'               => 'minutes',

            'Wind Sample Interval'          => 'seconds',
            'time_delta'                    => 'seconds',
            'stamp_delta'                   => 'seconds',
            'uptime'                        => 'seconds',
            'rssi'                          => 'rssi',
            'hub_rssi'                      => 'rssi',
            'Radio Status'                  => 'Radio_Status',
            'Slope'                         => 'slope',
            'Regression Slope'              => 'slope',
            'Battery Status'                => 'battery_status',
            'System Condition'              => 'system_condition',
            'Counter Slope Datasets'        => 'seconds',
            // Fix: Map sensor_status to the status profile
            'sensor_status'                 => 'status'
        ];
        return $mapping[$name] ?? 'text';
    }

    private function RegisterProfile($name, $type, $digits, $prefix, $suffix, $min, $max, $step, $associations)
    {
        if (strpos($name, '~') !== false) return;

        // Fix: Auto-delete profile if type mismatch exists
        if (IPS_VariableProfileExists($name) && IPS_GetVariableProfile($name)['ProfileType'] !== $type) {
            IPS_DeleteVariableProfile($name);
        }

        if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, $type);
        IPS_SetVariableProfileText($name, $prefix, $suffix);

        if ($type === 1 || $type === 2) {
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        if (($type === 1 || $type === 2) && $associations) {
            foreach ($associations['Text'] as $key => $text) {
                $assocValue = ($type === 2) ? (float)$key : (int)$key;
                IPS_SetVariableProfileAssociation($name, $assocValue, $text, '', $associations['Color'][$key] ?? -1);
            }
        }
    }


    private function MaintainVariableSafe($Ident, $Name, $Type, $Profile = "", $Position = 0, $Keep = true)
    {
        $varID = @$this->GetIDForIdent($Ident);
        if ($varID && IPS_GetVariable($varID)['VariableType'] !== $Type) {
            IPS_DeleteVariable($varID);
        }
        return $this->MaintainVariable($Ident, $Name, $Type, $Profile, $Position, $Keep);
    }


    public function SyncDashboardList()
    {
        $masterMetadata = $this->GetMasterMetadata();
        $listString = $this->ReadPropertyString('HTMLVariableList');
        $currentList = json_decode($listString, true) ?: [];
        $newList = [];
        $existingIdents = array_column($currentList, 'Ident');

        // 1. Refresh labels for all existing items
        foreach ($currentList as $item) {
            $ident = $item['Ident'] ?? '';
            if ($ident && isset($masterMetadata[$ident])) {
                $item['Label'] = $masterMetadata[$ident];
                $newList[] = $item;
            }
        }

        // 2. Add any newly discovered variables
        foreach ($masterMetadata as $ident => $label) {
            if (!in_array($ident, $existingIdents)) {
                $newList[] = ['Label' => $label, 'Show' => false, 'Row' => 1, 'Col' => 1, 'Ident' => $ident];
            }
        }

        IPS_SetProperty($this->InstanceID, 'HTMLVariableList', json_encode($newList));
        IPS_ApplyChanges($this->InstanceID);
        echo "Configuration grid synchronized and labels refreshed.";
    }

    private function GetMasterMetadata()
    {
        $config = $this->GetModuleConfig();
        $master = [];
        // Observations
        if (isset($config['descriptions']['obs_st'])) {
            foreach ($config['descriptions']['obs_st'] as $name) {
                if ($name == 'Rohdaten') continue;
                $master[str_replace([' ', '(', ')'], ['_', '', ''], $name)] = $name;
            }
        }
        // Device Status
        if (isset($config['descriptions']['device_status'])) {
            foreach ($config['descriptions']['device_status'] as $name) {
                if ($name == 'Rohdaten') continue;
                $ident = 'dev_' . str_replace(' ', '_', $name);
                $master[$ident] = 'Device: ' . $name;
                if ($name == 'sensor_status') {
                    $master[$ident . '_bit10'] = 'Device: Booster-Status (Bit 10)';
                    $master[$ident . '_bit11'] = 'Device: Booster-Status (Bit 11)';
                    $master[$ident . '_booster_empty'] = 'Device: Power-Booster erschöpft';
                    $master[$ident . '_booster_connected'] = 'Device: Power-Booster extern';
                }
            }
        }
        // Hub Status
        if (isset($config['descriptions']['hub_status'])) {
            foreach ($config['descriptions']['hub_status'] as $name) {
                if ($name == 'radio_stats' || $name == 'Rohdaten') continue;
                $master['hub_' . str_replace(' ', '_', $name)] = 'Hub: ' . $name;
            }
        }
        // Radio Stats
        if (isset($config['descriptions']['radio_stats'])) {
            foreach ($config['descriptions']['radio_stats'] as $name) {
                $master['hub_radio_' . str_replace(' ', '_', $name)] = 'Radio: ' . $name;
            }
        }
        return $master;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // 1. Handle Visibility in General Settings
        $authMode = $this->ReadPropertyInteger('AuthMode');
        foreach ($form['elements'] as &$panel) {
            if (isset($panel['caption']) && $panel['caption'] == 'General Settings') {
                foreach ($panel['items'] as &$item) {
                    if (in_array($item['name'], ['WebhookUser', 'WebhookPassword'])) {
                        $item['visible'] = ($authMode === 1);
                    }
                    if ($item['name'] == 'SecretsInstanceID') {
                        $item['visible'] = ($authMode === 2 || $authMode === 3);
                    }
                }
            }
        }
        unset($panel); // CRITICAL: Destroy reference to prevent corruption of subsequent loops
        unset($item);
        unset($panel);

        // 2. Prepare Dashboard Grid Data
        $master = $this->GetMasterMetadata();
        $bufferData = $this->ReadAttributeString('HTMLVariableListBuffer');
        $values = json_decode($bufferData, true) ?: [];

        if (empty($values)) {
            $values = json_decode($this->ReadPropertyString('HTMLVariableList'), true) ?: [];
        }

        $existingIdents = array_column($values, 'Ident');
        foreach ($values as &$val) {
            if (isset($val['Ident']) && isset($master[$val['Ident']])) {
                $val['Label'] = $master[$val['Ident']];
            }
        }

        $r = 1;
        $c = 1;
        foreach ($master as $ident => $label) {
            if (!in_array($ident, $existingIdents)) {
                $values[] = ['Label' => $label, 'Show' => false, 'ShowChart' => false, 'Row' => $r, 'Col' => $c, 'Ident' => $ident];
                $c++;
                if ($c > 4) {
                    $c = 1;
                    $r++;
                }
            }
        }

        $this->WriteAttributeString('HTMLVariableListBuffer', json_encode($values));

        // 3. Move List to Actions while keeping other Panel items
        // 3. Inject list values into the existing HTMLVariableList (keep it inside the panel)
        foreach ($form['elements'] as &$panel) {
            if (($panel['caption'] ?? '') === 'Dashboard Customization' && isset($panel['items']) && is_array($panel['items'])) {
                foreach ($panel['items'] as &$item) {
                    if (($item['name'] ?? '') === 'HTMLVariableList') {
                        $item['values'] = $values;
                        $item['onEdit'] = "TMT_UpdateDashboardRow(\$id, json_encode(\$HTMLVariableList));";
                    }
                }
                unset($item);
            }
        }
        unset($panel);


        return json_encode($form);
    }

    public function UpdateUI()
    {
        $authMode = $this->ReadPropertyInteger('AuthMode');

        // Toggle visibility based on selected mode
        // Mode 1 = Manual
        $this->UpdateFormField('WebhookUser', 'visible', ($authMode === 1));
        $this->UpdateFormField('WebhookPassword', 'visible', ($authMode === 1));

        // Mode 2 = Secrets Manager, Mode 3 = Passkey
        $this->UpdateFormField('SecretsInstanceID', 'visible', ($authMode === 2 || $authMode === 3));
    }

    public function UpdateDashboardRow(string $HTMLVariableList)
    {
        $newData = json_decode($HTMLVariableList, true);
        if (!is_array($newData)) return;

        if (isset($newData['Ident'])) {
            $newData = [$newData];
        }

        $buffer = json_decode($this->ReadAttributeString('HTMLVariableListBuffer'), true) ?: [];

        $map = [];
        foreach ($buffer as $item) {
            if (isset($item['Ident'])) $map[$item['Ident']] = $item;
        }

        foreach ($newData as $row) {
            if (isset($row['Ident'])) {
                $row['Row'] = (int)$row['Row'];
                $row['Col'] = (int)$row['Col'];
                $row['Show'] = (bool)$row['Show'];
                $row['ShowChart'] = (bool)($row['ShowChart'] ?? false);
                $map[$row['Ident']] = $row;
            }
        }

        $this->WriteAttributeString('HTMLVariableListBuffer', json_encode(array_values($map)));
        $this->SendDebug('UI-Update', 'RAM-Cache updated and types cast.', 0);
    }

    public function SaveSelections()
    {
        // Section 4 Summary: Committing RAM Attribute to Property
        $buffer = $this->ReadAttributeString('HTMLVariableListBuffer');

        if ($buffer === '' || $buffer === '[]') {
            echo "Selection list is empty. Please open the configuration form first.";
            return;
        }

        IPS_SetProperty($this->InstanceID, 'HTMLVariableList', $buffer);
        IPS_ApplyChanges($this->InstanceID);

        echo "Dashboard selection saved and applied.";
    }

    public function UpdateDashboard()
    {
        $this->GenerateHTMLDashboard();
    }
}
