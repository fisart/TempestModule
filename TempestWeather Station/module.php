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
        parent::Create();

        // Register Properties from form.json
        $this->RegisterPropertyString('ProfilePrefix', 'Tempest_');
        $this->RegisterPropertyBoolean('ExperimentalRegression', true);
        $this->RegisterPropertyString('TriggerValueSlope', '0.000004');
        $this->RegisterPropertyInteger('RegressionDataPoints', 45);

        // Message Type Toggles
        $this->RegisterPropertyBoolean('MsgObsSt', true);
        $this->RegisterPropertyBoolean('MsgDeviceStatus', true);
        $this->RegisterPropertyBoolean('MsgHubStatus', true);
        $this->RegisterPropertyBoolean('MsgRapidWind', true);
        $this->RegisterPropertyBoolean('MsgPrecip', true);
        $this->RegisterPropertyBoolean('MsgStrike', true);

        $this->RegisterPropertyBoolean('EnableHTML', true);
        $this->RegisterVariableString('Dashboard', 'Dashboard', '~HTMLBox', 150);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->UpdateProfiles();
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
            $val = $obs[$index];
            $ident = str_replace([' ', '(', ')'], ['_', '', ''], $name);

            if (strpos($name, 'Wind') !== false && $name != 'Wind Direction') {
                $val = $val * 3.6;
            }

            $this->MaintainVariable($ident, $name, 2, $prefix . $this->GetProfileForName($name), $index, true);
            $this->HandleValueUpdate($ident, $val, $timestamp, $check);
        }

        if ($this->ReadPropertyBoolean('ExperimentalRegression')) {
            $this->UpdateBatteryLogic($obs[16]);
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

        $history = AC_GetLoggedValues($archiveID, $batteryID, time() - 86400, time(), $nrPoints);
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

        $statusID = $this->MaintainVariable('Battery_Status', 'Battery Status', 0, $prefix . 'battery_status', 23, true);
        $slopeID = $this->MaintainVariable('Slope', 'Regression Slope', 2, $prefix . 'slope', 22, true);

        $oldSlope = GetValue($slopeID);
        $isCharging = GetValue($statusID);

        $newState = $isCharging ? ($slope >= $triggerSlope && $slope >= $oldSlope) : ($slope >= $triggerSlope && $slope > $oldSlope);

        SetValue($this->MaintainVariable('Average', 'Average Voltage', 2, $prefix . 'volt', 20, true), $this->calculate_average($y));
        SetValue($slopeID, $slope);
        SetValue($statusID, $newState);
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

    private function HandleValueUpdate(string $ident, $value, int $timestamp, string $check)
    {
        $varID = $this->GetIDForIdent($ident);
        if ($check === 'OLD_TIME_STAMP') {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            AC_AddLoggedValues($archiveID, $varID, [['TimeStamp' => $timestamp, 'Value' => $value]]);
            AC_ReAggregateVariable($archiveID, $varID);
        } else {
            SetValue($varID, $value);
        }
    }
    private function GenerateHTMLDashboard()
    {
        if (!$this->ReadPropertyBoolean('EnableHTML')) return;

        $temp = @GetValue($this->GetIDForIdent('Air_Temperature')) ?? '--';
        $hum  = @GetValue($this->GetIDForIdent('Relative_Humidity')) ?? '--';
        $wind = @GetValue($this->GetIDForIdent('Wind_Avg')) ?? '--';
        $batt = @GetValue($this->GetIDForIdent('Battery')) ?? '--';
        $state = @GetValue($this->GetIDForIdent('Battery_Status')) ? 'Charging' : 'Discharging';

        $html = "
        <div style='font-family:sans-serif; padding:10px; border-radius:10px; background-color:rgba(0,0,0,0.1);'>
            <h3 style='margin:0 0 10px 0;'>Tempest Station Status</h3>
            <div style='display:flex; justify-content:space-between;'>
                <div><b>Temp:</b> $temp °C</div>
                <div><b>Humidity:</b> $hum %</div>
            </div>
            <div style='display:flex; justify-content:space-between; margin-top:5px;'>
                <div><b>Wind:</b> $wind km/h</div>
                <div><b>Battery:</b> $batt V ($state)</div>
            </div>
        </div>";

        $this->SetValue('Dashboard', $html);
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
                'obs_st' => [
                    0 => 'Time Epoch',
                    1 => 'Wind Lull',
                    2 => 'Wind Avg',
                    3 => 'Wind Gust',
                    4 => 'Wind Direction',
                    7 => 'Air Temperature',
                    8 => 'Relative Humidity',
                    11 => 'Solar Radiation',
                    12 => 'Precip Accumulated',
                    16 => 'Battery'
                ],
                'device_status' => [4 => 'uptime', 5 => 'voltage', 7 => 'rssi', 8 => 'hub_rssi', 9 => 'sensor_status'],
                'hub_status' => [3 => 'uptime', 4 => 'rssi', 9 => 'radio_stats'],
                'radio_stats' => [1 => 'Reboot Count', 2 => 'I2C Bus Error Count', 3 => 'Radio Status']
            ],
            'profiles' => [
                'km_pro_stunde'    => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' km/h', 'min' => 0, 'max' => 160, 'step' => 0.01],
                'celcius'          => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' °C', 'min' => -40, 'max' => 45, 'step' => 0.01],
                'volt'             => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => ' V', 'min' => 0, 'max' => 4, 'step' => 0.001],
                'percent'          => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' %', 'min' => 0, 'max' => 100, 'step' => 0.01],
                'milli_bar'        => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' hPa', 'min' => 0, 'max' => 9999, 'step' => 1],
                'wind_direction'   => ['type' => 2, 'digits' => 1, 'prefix' => '', 'suffix' => ' °', 'min' => 0, 'max' => 360, 'step' => 1],
                'energy'           => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' W/m²', 'min' => 0, 'max' => 1000, 'step' => 1],
                'km'               => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' km', 'min' => 0, 'max' => 100, 'step' => 0.01],
                'rssi'             => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' dB', 'min' => -100, 'max' => 0, 'step' => 1],
                'seconds'          => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => ' s', 'min' => 0, 'max' => 999999999, 'step' => 1],
                'UnixTimestamp'   => ['type' => 1, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 999999999, 'step' => 1],
                'slope'            => ['type' => 2, 'digits' => 9, 'prefix' => '', 'suffix' => ' mx+b', 'min' => -10, 'max' => 10, 'step' => 0.00000001],
                'battery_status'   => ['type' => 0, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 1, 'step' => 0, 'associations' => ['Text' => [false => 'Discharge', true => 'Charge'], 'Color' => [false => $red, true => $green]]],
                'system_condition' => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 3, 'step' => 0.001, 'associations' => null],
                'text'             => ['type' => 3, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 0, 'step' => 0]
            ],
            'charge' => [
                'Text' => [0 => ' + Hibernate', 4 => ' + Wind 1m', 6 => ' + Wind 6s', 8 => ' + Full Perf'],
                'Color' => [0 => $purple, 4 => $orange, 6 => $yellow, 8 => $green]
            ],
            'discharge' => [
                'Text' => [0 => ' - Hibernate', 4 => ' - Wind 1m', 6 => ' - Wind 6s', 8 => ' - Full Perf'],
                'Color' => [0 => $purple, 4 => $orange, 6 => $yellow, 8 => $green]
            ]
        ];
    }

    private function GetProfileForName(string $name)
    {
        $mapping = [
            'Air Temperature' => 'celcius',
            'Relative Humidity' => 'percent',
            'Wind Avg' => 'km_pro_stunde',
            'Battery' => 'volt',
            'Time Epoch' => 'UnixTimestamp',
            'timestamp' => 'UnixTimestamp'
        ];
        return $mapping[$name] ?? 'text';
    }

    private function RegisterProfile($name, $type, $digits, $prefix, $suffix, $min, $max, $step, $associations)
    {
        // Fix: Do not allow ~ anywhere in custom profile names
        if (strpos($name, '~') !== false) return;
        if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, $type);
        IPS_SetVariableProfileText($name, $prefix, $suffix);

        if ($type == 1 || $type == 2) {
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        if ($type != 3 && $associations) {
            foreach ($associations['Text'] as $key => $text) {
                IPS_SetVariableProfileAssociation($name, (float)$key, $text, '', $associations['Color'][$key] ?? -1);
            }
        }
    }
    private function ProcessDeviceStatus(array $data)
    {
        $timestamp = $data['timestamp'];
        $check = $this->CheckTimestamp('timestamp_device', $timestamp);
        if ($check === 'INVALID') return;

        $config = $this->GetModuleConfig();
        $prefix = $this->ReadPropertyString('ProfilePrefix');

        foreach ($config['descriptions']['device_status'] as $index => $name) {
            if (!isset($data[$name])) continue;
            $val = $data[$name];
            $ident = 'dev_' . str_replace(' ', '_', $name);

            // Mask sensor status (Ported from line 412)
            if ($name == 'sensor_status') $val = $val & bindec('111111111');

            $this->MaintainVariable($ident, $name, 1, $prefix . $this->GetProfileForName($name), $index + 50, true);
            $this->HandleValueUpdate($ident, $val, $timestamp, $check);
        }
    }

    private function ProcessHubStatus(array $data)
    {
        if (!isset($data['timestamp'])) return;
        $timestamp = $data['timestamp'];
        $check = $this->CheckTimestamp('timestamp_hub', $timestamp);
        if ($check === 'INVALID') return;

        $config = $this->GetModuleConfig();
        $prefix = $this->ReadPropertyString('ProfilePrefix');

        foreach ($config['descriptions']['hub_status'] as $index => $name) {
            if ($name === null || !isset($data[$name])) continue;
            $val = $data[$name];

            if ($name == 'radio_stats' && is_array($val)) {
                foreach ($val as $subIndex => $subVal) {
                    if (!isset($config['descriptions']['radio_stats'][$subIndex])) continue;
                    $subName = $config['descriptions']['radio_stats'][$subIndex];
                    $subIdent = 'hub_radio_' . str_replace(' ', '_', $subName);
                    $this->MaintainVariable($subIdent, $subName, 1, $prefix . $this->GetProfileForName($subName), $subIndex + 100, true);
                    $this->HandleValueUpdate($subIdent, $subVal, $timestamp, $check);
                }
            } else {
                $ident = 'hub_' . str_replace(' ', '_', $name);
                $this->MaintainVariable($ident, $name, 1, $prefix . $this->GetProfileForName($name), $index + 80, true);
                $this->HandleValueUpdate($ident, $val, $timestamp, $check);
            }
        }
    }

    private function ProcessRapidWind(array $data)
    {
        $timestamp = $data['ob'][0];
        $check = $this->CheckTimestamp('Time_Epoch_Wind', $timestamp);
        if ($check === 'INVALID') return;
        $prefix = $this->ReadPropertyString('ProfilePrefix');

        $this->MaintainVariable('Wind_Speed', 'Wind Speed', 2, $prefix . 'km_pro_stunde', 10, true);
        $this->HandleValueUpdate('Wind_Speed', $data['ob'][1] * 3.6, $timestamp, $check);

        $this->MaintainVariable('Wind_Direction_Rapid', 'Wind Direction (Rapid)', 1, $prefix . 'wind_direction', 11, true);
        $this->HandleValueUpdate('Wind_Direction_Rapid', $data['ob'][2], $timestamp, $check);
    }

    private function ProcessPrecip(array $data)
    {
        $timestamp = $data['evt'][0];
        $prefix = $this->ReadPropertyString('ProfilePrefix');
        $this->MaintainVariable('Rain_Start_Event', 'Rain Start Event', 1, $prefix . 'UnixTimestamp', 120, true);
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
}
