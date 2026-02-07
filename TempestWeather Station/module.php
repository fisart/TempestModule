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

        // Battery Regression
        $this->RegisterPropertyBoolean('ExperimentalRegression', true);
        $this->RegisterPropertyString('TriggerValueSlope', '0.000004');
        $this->RegisterPropertyInteger('RegressionDataPoints', 45);

        // Visualization & Dashboard
        $this->RegisterPropertyBoolean('EnableHTML', true);
        $this->RegisterPropertyInteger('HTMLBackgroundColor', 0x222222);
        $this->RegisterPropertyInteger('HTMLFontColor', 0xFFFFFF);
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
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->UpdateProfiles();

        // Final Synchronization from Attribute (RAM) to Property (Disk)
        // This prevents the "Zwei-Welten-Falle" (PDF Section 2B)
        $buffer = $this->ReadAttributeString('HTMLVariableListBuffer');
        $property = $this->ReadPropertyString('HTMLVariableList');

        // Only update property and restart if the RAM-Cache differs from stored data
        if ($buffer !== $property && $buffer !== '[]' && $buffer !== '') {
            IPS_SetProperty($this->InstanceID, 'HTMLVariableList', $buffer);
            IPS_ApplyChanges($this->InstanceID);
            return; // Terminate this cycle; the next automatic ApplyChanges will proceed
        }

        $this->GenerateHTMLDashboard();
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

    private function UpdateBatteryLogic(float $currentVoltage)
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $nrPoints = $this->ReadPropertyInteger('RegressionDataPoints');
        $triggerSlope = (float)$this->ReadPropertyString('TriggerValueSlope');
        $prefix = $this->ReadPropertyString('ProfilePrefix');

        $batteryID = @$this->GetIDForIdent('Battery');
        if (!$batteryID) return;

        if (!AC_GetLoggingStatus($archiveID, $batteryID)) {
            AC_SetLoggingStatus($archiveID, $batteryID, true);
            IPS_ApplyChanges($archiveID);
            return;
        }

        $history = @AC_GetLoggedValues($archiveID, $batteryID, time() - 86400, time(), $nrPoints);
        if (!is_array($history) || count($history) < 5) return;

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

        // Maintain the variables
        $this->MaintainVariableSafe('Battery_Status', 'Battery Status', $config['profiles']['battery_status']['type'], $prefix . 'battery_status', 23, true);
        $this->MaintainVariableSafe('Slope', 'Regression Slope', $config['profiles']['slope']['type'], $prefix . 'slope', 22, true);
        $this->MaintainVariableSafe('Average', 'Average Voltage', $config['profiles']['volt']['type'], $prefix . 'volt', 20, true);

        // Fix: Retrieve actual IDs instead of using the boolean return value of MaintainVariable
        $statusID = $this->GetIDForIdent('Battery_Status');
        $slopeID = $this->GetIDForIdent('Slope');
        $avgID = $this->GetIDForIdent('Average');

        $oldSlope = GetValue($slopeID);
        $isCharging = (bool)GetValue($statusID);
        $newState = $isCharging ? ($slope >= $triggerSlope && $slope >= $oldSlope) : ($slope >= $triggerSlope && $slope > $oldSlope);

        SetValue($avgID, $this->calculate_average($y));
        SetValue($slopeID, $slope);
        SetValue($statusID, (int)$newState);
    }

    private function GenerateHTMLDashboard()
    {
        if (!$this->ReadPropertyBoolean('EnableHTML')) return;

        $stationName = $this->ReadPropertyString('StationName');
        $bgColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLBackgroundColor'));
        $fontColor = sprintf("#%06X", $this->ReadPropertyInteger('HTMLFontColor'));
        $varList = json_decode($this->ReadPropertyString('HTMLVariableList'), true) ?: [];
        $master = $this->GetMasterMetadata();

        $itemsHtml = "";
        foreach ($varList as $item) {
            // Fix: Guard clause to prevent "Undefined array key Ident" warnings
            if (!isset($item['Ident']) || !($item['Show'] ?? false)) continue;

            $varID = @$this->GetIDForIdent($item['Ident']);
            $formatted = ($varID && IPS_VariableExists($varID)) ? GetValueFormatted($varID) : '--';
            $label = (!empty($item['Label'])) ? $item['Label'] : ($master[$item['Ident']] ?? $item['Ident']);

            $itemsHtml .= "
            <div style='grid-row: {$item['Row']}; grid-column: {$item['Col']}; border: 1px solid rgba(255,255,255,0.1); padding: 1cqi; text-align: center; display: flex; flex-direction: column; justify-content: center; border-radius: 4px;'>
                <div style='font-size: 3cqi; opacity: 0.8; margin-bottom: 0.2cqi;'>$label</div>
                <div style='font-size: 5cqi; font-weight: bold;'>$formatted</div>
            </div>";
        }

        $html = "
        <div style='container-type: inline-size; background-color: $bgColor; color: $fontColor; font-family: Segoe UI, sans-serif; height: 100%; width: 100%; box-sizing: border-box; display: flex; flex-direction: column; padding: 2cqi; border-radius: 8px;'>
            <div style='text-align: center; font-size: 6cqi; font-weight: bold; padding-bottom: 2cqi; border-bottom: 1px solid rgba(255,255,255,0.2);'>$stationName</div>
            <div style='display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: 1fr; gap: 1.5cqi; flex-grow: 1; margin-top: 2cqi;'>$itemsHtml</div>
        </div>";

        $this->SetValue('Dashboard', $html);
    }

    private function HandleValueUpdate(string $ident, $value, int $timestamp, string $check)
    {
        if ($value === null) return;
        $varID = @$this->GetIDForIdent($ident);
        if (!$varID) return;

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
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            AC_AddLoggedValues($archiveID, $varID, [['TimeStamp' => $timestamp, 'Value' => $value]]);
            AC_ReAggregateVariable($archiveID, $varID);
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

        foreach ($config['descriptions']['device_status'] as $index => $name) {
            if (!isset($data[$name]) || is_array($data[$name])) continue;
            $val = $data[$name];
            $ident = 'dev_' . str_replace(' ', '_', $name);
            if ($name == 'sensor_status') $val = $val & bindec('111111111');

            $profileIdent = $this->GetProfileForName($name);
            $this->MaintainVariable($ident, $name, $config['profiles'][$profileIdent]['type'], $prefix . $profileIdent, $index + 50, true);
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

        $this->MaintainVariable('Wind_Speed', 'Wind Speed', 2, $prefix . 'km_pro_stunde', 10, true);
        $this->HandleValueUpdate('Wind_Speed', $data['ob'][1] * 3.6, $timestamp, 'NEW_VALUE');

        $this->MaintainVariable('Wind_Direction_Rapid', 'Wind Direction (Rapid)', 2, $prefix . 'wind_direction', 11, true);
        $this->HandleValueUpdate('Wind_Direction_Rapid', $data['ob'][2], $timestamp, 'NEW_VALUE');
    }

    private function ProcessPrecip(array $data)
    {
        $timestamp = $data['evt'][0];
        $this->MaintainVariable('Rain_Start_Event', 'Rain Start Event', 1, $this->ReadPropertyString('ProfilePrefix') . 'UnixTimestamp', 120, true);
        $this->HandleValueUpdate('Rain_Start_Event', $timestamp, $timestamp, 'NEW_VALUE');
    }

    private function ProcessStrike(array $data)
    {
        $timestamp = $data['evt'][0];
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        $this->MaintainVariable('Strike_Distance', 'Distance', 2, $prefix . 'km', 130, true);
        $this->HandleValueUpdate('Strike_Distance', $data['evt'][1], $timestamp, 'NEW_VALUE');
        $this->MaintainVariable('Strike_Energy', 'Energy', 1, $prefix . 'energy', 131, true);
        $this->HandleValueUpdate('Strike_Energy', $data['evt'][2], $timestamp, 'NEW_VALUE');
    }

    private function CheckTimestamp(string $ident, int $timestamp)
    {
        $varID = @$this->GetIDForIdent($ident);
        if (!$varID) return 'NEW_VALUE';
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        if (!AC_GetLoggingStatus($archiveID, $varID)) return 'NEW_VALUE';
        if ($timestamp > time()) return 'INVALID';
        $lastValues = AC_GetLoggedValues($archiveID, $varID, $timestamp - 1, $timestamp + 1, 1);
        if (!empty($lastValues) && $lastValues[0]['Value'] == $timestamp) return 'INVALID';
        return ($timestamp < IPS_GetVariable($varID)['VariableUpdated']) ? 'OLD_TIME_STAMP' : 'NEW_VALUE';
    }

    public function UpdateProfiles()
    {
        $prefix = $this->ReadPropertyString('ProfilePrefix');
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
                'wind_direction' => ['type' => 2, 'digits' => 1, 'prefix' => '', 'suffix' => ' °', 'min' => 0, 'max' => 360, 'step' => 1],
                'energy' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' W/m²', 'min' => 0, 'max' => 1000, 'step' => 1],
                'lux' => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' Lx', 'min' => 0, 'max' => 120000, 'step' => 1],
                'index' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' UVI', 'min' => 0, 'max' => 15, 'step' => 0.01],
                'mm' => ['type' => 2, 'digits' => 6, 'prefix' => '', 'suffix' => ' mm', 'min' => 0, 'max' => 100, 'step' => 0.000001],
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
                'text' => ['type' => 3, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 0, 'step' => 0]
            ],
            'charge' => ['Text' => [0 => '+ Hibernate', 4 => '+ Wind 1m', 6 => '+ Wind 6s', 8 => '+ Full Perf'], 'Color' => [0 => $purple, 4 => $orange, 6 => $yellow, 8 => $green]],
            'discharge' => ['Text' => [0 => '- Hibernate', 4 => '- Wind 1m', 6 => '- Wind 6s', 8 => '- Full Perf'], 'Color' => [0 => $purple, 4 => $orange, 6 => $yellow, 8 => $green]]
        ];
    }

    private function GetProfileForName(string $name)
    {
        $mapping = ['Air Temperature' => 'celcius', 'Relative Humidity' => 'percent', 'Wind Avg' => 'km_pro_stunde', 'Wind Lull' => 'km_pro_stunde', 'Wind Gust' => 'km_pro_stunde', 'Wind Speed' => 'km_pro_stunde', 'Battery' => 'volt', 'voltage' => 'volt', 'Average' => 'volt', 'Median' => 'volt', 'Time Epoch' => 'UnixTimestamp', 'timestamp' => 'UnixTimestamp', 'Rain Start Event' => 'UnixTimestamp', 'Station Pressure' => 'milli_bar', 'Wind Direction' => 'wind_direction', 'Illuminance' => 'lux', 'UV' => 'index', 'Solar Radiation' => 'energy', 'Energy' => 'energy', 'Precip Accumulated' => 'mm', 'Precipitation Type' => 'perception_type', 'Lightning Strike Avg Distance' => 'km', 'Distance' => 'km', 'Lightning Strike Count' => 'seconds', 'Report Interval' => 'minutes', 'Wind Sample Interval' => 'seconds', 'time_delta' => 'seconds', 'stamp_delta' => 'seconds', 'uptime' => 'seconds', 'rssi' => 'rssi', 'hub_rssi' => 'rssi', 'Radio Status' => 'Radio_Status', 'Slope' => 'slope', 'Battery Status' => 'battery_status', 'System Condition' => 'system_condition'];
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
        foreach ($config['descriptions']['obs_st'] as $name) {
            if ($name == 'Rohdaten') continue;
            $master[str_replace([' ', '(', ')'], ['_', '', ''], $name)] = $name;
        }
        foreach ($config['descriptions']['device_status'] as $name) {
            if ($name == 'Rohdaten') continue;
            $master['dev_' . str_replace(' ', '_', $name)] = 'Device: ' . $name;
        }
        foreach ($config['descriptions']['hub_status'] as $name) {
            if ($name == 'radio_stats' || $name == 'Rohdaten') continue;
            $master['hub_' . str_replace(' ', '_', $name)] = 'Hub: ' . $name;
        }
        foreach ($config['descriptions']['radio_stats'] as $name) {
            $master['hub_radio_' . str_replace(' ', '_', $name)] = 'Radio: ' . $name;
        }
        return $master;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $master = $this->GetMasterMetadata();

        // Sync Property to Attribute (Initial Sync from PDF Section 3, Step 2)
        $propertyData = $this->ReadPropertyString('HTMLVariableList');
        $this->WriteAttributeString('HTMLVariableListBuffer', $propertyData);

        $values = json_decode($propertyData, true) ?: [];
        $existingIdents = array_column($values, 'Ident');

        // Refresh labels and add missing variables for the UI
        foreach ($values as &$val) {
            if (isset($val['Ident']) && isset($master[$val['Ident']])) {
                $val['Label'] = $master[$val['Ident']];
            }
        }
        foreach ($master as $ident => $label) {
            if (!in_array($ident, $existingIdents)) {
                $values[] = ['Label' => $label, 'Show' => false, 'Row' => 1, 'Col' => 1, 'Ident' => $ident];
            }
        }

        // Move the list to Actions (Stateless UI from PDF Section 3, Step 1)
        foreach ($form['elements'] as $k => $panel) {
            if (isset($panel['caption']) && $panel['caption'] == 'Dashboard Customization') {
                foreach ($panel['items'] as $i => $item) {
                    // Fix: Added isset($item['name']) to handle Buttons/Labels without names
                    if (isset($item['name']) && $item['name'] == 'HTMLVariableList') {
                        $listComponent = $item;
                        $listComponent['values'] = $values;
                        // Add the onEdit handler (PDF Section 3, Step 3)
                        $listComponent['onEdit'] = "TMT_UpdateDashboardRow(\$id, json_encode(\$HTMLVariableList));";

                        $form['actions'][] = $listComponent;
                        unset($form['elements'][$k]['items'][$i]);
                    }
                }
                $form['elements'][$k]['items'] = array_values($form['elements'][$k]['items']);
            }
        }

        return json_encode($form);
    }

    public function UpdateDashboardRow(string $HTMLVariableList)
    {
        // Section 3, Step 2: Write to RAM-Cache (Attribute) immediately on every click
        $this->SendDebug('UI-Update', $HTMLVariableList, 0);
        $this->WriteAttributeString('HTMLVariableListBuffer', $HTMLVariableList);
    }
}
