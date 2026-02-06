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
                'obs_st' => [0 => 'Time Epoch', 1 => 'Wind Lull', 2 => 'Wind Avg', 3 => 'Wind Gust', 4 => 'Wind Direction', 7 => 'Air Temperature', 8 => 'Relative Humidity', 16 => 'Battery']
            ],
            'profiles' => [
                'km_pro_stunde' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' km/h', 'min' => 0, 'max' => 160, 'step' => 0.01],
                'celcius' => ['type' => 2, 'digits' => 2, 'prefix' => '', 'suffix' => ' °C', 'min' => -40, 'max' => 45, 'step' => 0.01],
                'volt' => ['type' => 2, 'digits' => 3, 'prefix' => '', 'suffix' => ' V', 'min' => 0, 'max' => 4, 'step' => 0.001],
                'battery_status' => ['type' => 0, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 1, 'step' => 0, 'associations' => ['Text' => [false => 'Discharge', true => 'Charge'], 'Color' => [false => $red, true => $green]]],
                'text' => ['type' => 3, 'digits' => 0, 'prefix' => '', 'suffix' => '', 'min' => 0, 'max' => 0, 'step' => 0]
            ]
        ];
    }

    private function GetProfileForName(string $name)
    {
        $mapping = ['Air Temperature' => 'celcius', 'Relative Humidity' => 'percent', 'Wind Avg' => 'km_pro_stunde', 'Battery' => 'volt'];
        return $mapping[$name] ?? 'text';
    }

    private function RegisterProfile($name, $type, $digits, $prefix, $suffix, $min, $max, $step, $associations)
    {
        if (strpos($name, '~') === 0) return;
        if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, $type);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        if ($associations) {
            foreach ($associations['Text'] as $key => $text) IPS_SetVariableProfileAssociation($name, (float)$key, $text, '', $associations['Color'][$key] ?? -1);
        }
    }
}
