<?php

declare(strict_types=1);

// CLASS TempestWeather Station
class TempestWeather Station extends IPSModuleStrict
{

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        if ((float) IPS_GetKernelVersion() < 8.2) {
            $this->RequireParent('{82347F20-F541-41E1-AC5B-A636FD3AE2D8}');
        }

        // Set visualization type to 1, as we want to offer HTML
        $this->SetVisualizationType(1);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * The function returns a JSON-encoded object that describes compatible parent instances. 
     * The management console uses this information to suggest suitable parent instances
     * when creating or customising the instance.
     *
     * @return string JSON-encoded object
     */
    public function GetCompatibleParents(): string
    {
        // return '{"type": "require", "moduleIDs": ["{12345678-1234-5678-ABCDEFABCDEF}"]}';
        // return '{"type": "connect", "moduleIDs": ["{12345678-1234-5678-ABCDEFABCDEF}"]}';
        return $this->module->GetConfigurationForParent();
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Set status
        $this->SetStatus(102);
    }

    /**
     * This function sends the text message to all of his children.
     *
     * @param string $text Text message
     *
     * @return void
     */
    public function Send(string $Text, string $Text, string $ClientIP, int $ClientPort): void
    {
        $this->SendDataToParent(json_encode(['DataID' => '{8E4D9B23-E0F2-1E05-41D8-C21EA53B8706},{C8792760-65CF-4C53-B5C7-A30FCC84FEFE},{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', "ClientIP" => $ClientIP, "ClientPort" => $ClientPort, "Buffer" => $Text]));
    }

    /**
     * This function is called by IP-Symcon and processes sent data and, if necessary, forwards it to
     * all child instances. Data can be sent using the SendDataToChildren function.
     *
     * @param string $json Data package in JSON format
     *
     * @return string Optional response to the parent instance
     */
    public function ReceiveData(string $json): string
    {
        $data = json_decode($json);
        IPS_LogMessage('Device RECV', utf8_decode($data->Buffer . ' - ' . $data->ClientIP . ' - ' . $data->ClientPort));
    }



    /**
     * If the HTML-SDK is to be used, this function must be overwritten in order to return the HTML content.
     *
     * @return string Initial display of a representation via HTML SDK
     */
    public function GetVisualizationTile(): string
    {
        // Add a script to set the values when loading, analogous to changes at runtime
        // Although the return from GetFullUpdateMessage is already JSON-encoded, json_encode is still executed a second time
        // This adds quotation marks to the string and any quotation marks within it are escaped correctly
        $handling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        // Add static HTML from file
        $module = file_get_contents(__DIR__ . '/module.html');
        // Important: $initialHandling at the end, as the handleMessage function is only defined in the HTML
        return $module . $handling;
    }

    /**
     * Generate a message that updates all elements in the HTML display.
     *
     * @return string JSON encoded message information
     */
    private function GetFullUpdateMessage(): string
    {
        // Fill resultset
        $result = [];
        $result['text'] = "Here's the text!";
        $result['bgcolor'] = $this->ReadPropertyInteger('PriceFont');
        $this->SendDebug(__FUNCTION__, print_r($result, true), 0);
        // send it
        return json_encode($result);
    }
}