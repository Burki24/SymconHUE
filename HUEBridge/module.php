<?php

class HUEBridge extends IPSModule {

  private $Host = "";
  private $User = "";
  private $LightsCategory = 0;

  public function Create() {
    parent::Create();
    $this->RegisterPropertyString("Host", "");
    $this->RegisterPropertyString("User", "SymconHUE");
    $this->RegisterPropertyInteger("LightsCategory", 0);
    $this->RegisterPropertyInteger("UpdateInterval", 5);
  }

  public function ApplyChanges() {
    $this->Host = "";
    $this->User = "";
    $this->CategoryLights = 0;

    parent::ApplyChanges();

    $this->RegisterTimer('UPDATE', $this->ReadPropertyString('UpdateInterval'), 'HUE_SyncStates($id)');

    $this->ValidateConfiguration();
  }

  protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }

    if (!$id) {
      $id = IPS_CreateEvent(1);
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }

    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");

    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");

    if (!($interval > 0)) {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);
      IPS_SetEventActive($id, false);
    } else {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $interval);
      IPS_SetEventActive($id, true);
    }
  }

  private function ValidateConfiguration() {
    if ($this->ReadPropertyInteger('LightsCategory') == 0 ||  $this->ReadPropertyString('Host') == '' || $this->ReadPropertyString('User') == '') {
      $this->SetStatus(104);
    } else {
      $this->Request("/lights", null);
    }
  }

  private function GetLightsCategory() {
    if($this->LightsCategory == '') $this->LightsCategory = $this->ReadPropertyString('LightsCategory');
    return $this->LightsCategory;
  }

  private function GetHost() {
    if($this->Host == '') $this->Host = $this->ReadPropertyString('Host');
    return $this->Host;
  }

  private function GetUser() {
    if($this->User == '') {
      $this->User = $this->ReadPropertyString('User');
      if (!preg_match('/[a-f0-9]{32}/i', $this->User)) {
        $this->User = md5($this->User);
      }
    }
    return $this->User;
  }

  public function Request($path, $data = null) {
    $host = $this->GetHost();
    $user = $this->GetUser();

    // Workaround for RPi
    if (!IPS_SemaphoreEnter('CURL', 5000)) {
      IPS_LogMessage('CURL', 'Semaphore Timeout');
      exit;
    }

    $client = curl_init();
    curl_setopt($client, CURLOPT_URL, "http://$host:80/api/$user$path");
    curl_setopt($client, CURLOPT_USERAGENT, "SymconHUE");
    curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($client, CURLOPT_TIMEOUT, 5);
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    if (isset($data)) curl_setopt($client, CURLOPT_CUSTOMREQUEST, 'PUT');
    if (isset($data)) curl_setopt($client, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($client);
    $status = curl_getinfo($client, CURLINFO_HTTP_CODE);
    curl_close($client);

    // Workaround for RPi
    IPS_SemaphoreLeave('CURL');

    if ($status != '200') {
      $this->SetStatus(203);
      return false;
    } else {
      $result = json_decode($result);

      if (is_array($result) && isset($result[0]->error)) {
        if(@($result[0]->error->description) == 'unauthorized user') {
          $this->SetStatus(201);
          return false;
        } else {
          $this->SetStatus(299);
          return false;
        }
      }

      if (isset($data)) {
        $this->SetStatus(102);
        return true;
      } else {
        $this->SetStatus(102);
        return $result;
      }
    }
  }

  public function RegisterUser() {
    $host = $this->GetHost();
    $json = json_encode(array('username' => $this->GetUser(), 'devicetype' => "IPS"));
    $lenght = strlen($json);

    $client = curl_init();
    curl_setopt($client, CURLOPT_URL, "http://$host:80/api");
    curl_setopt($client, CURLOPT_USERAGENT, "SymconHUE");
    curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($client, CURLOPT_TIMEOUT, 5);
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($client, CURLOPT_POST, true);
    curl_setopt($client, CURLOPT_POSTFIELDS, $json);
    $result = curl_exec($client);
    $status = curl_getinfo($client, CURLINFO_HTTP_CODE);
    curl_close($client);

    if ($status != '200') {
      $this->SetStatus(203);
      return false;
    } else {
      $result = json_decode($result);
      if(@isset($result[0]->error)) {
        $this->SetStatus(202);
      } else {
        $this->SetStatus(102);
      }
    }
  }

  /*
   * HUE_SyncDevices($bridgeId)
   * Abgleich aller Lampen
   */
  public function SyncDevices() {
    $lightsCategoryId = $this->GetLightsCategory();

    $lights = $this->Request('/lights');
    if ($lights) {
      foreach ($lights as $lightId => $light) {
        $name = utf8_decode((string)$light->name);
        $uniqueId = (string)$light->uniqueid;
        echo "$lightId. $name ($uniqueId)\n";

        $deviceId = $this->GetDeviceByUniqueId($uniqueId);

        if ($deviceId == 0) {
          $deviceId = IPS_CreateInstance($this->DeviceGuid());
          IPS_SetProperty($deviceId, 'UniqueId', $uniqueId);
        }

        IPS_SetParent($deviceId, $lightsCategoryId);
        IPS_SetProperty($deviceId, 'LightId', (integer)$lightId);
        IPS_SetName($deviceId, $name);

        // Verbinde Light mit Bridge
        if (IPS_GetInstance($deviceId)['ConnectionID'] <> $this->InstanceID) {
          @IPS_DisconnectInstance($deviceId);
          IPS_ConnectInstance($deviceId, $this->InstanceID);
        }

        IPS_ApplyChanges($deviceId);
        HUE_RequestData($deviceId);
      }
    }
  }

  /*
   * HUE_SyncStates($bridgeId)
   * Abgleich des Status aller Lampen
   */
  public function SyncStates() {
    $lightsCategoryId = $this->ReadPropertyInteger("LightsCategory");
    if(!(@$lightsCategoryId > 0)) throw new Exception("Lampenkategorie muss ausgefüllt sein");

    $lights = $this->Request('/lights');
    if ($lights) {
      foreach ($lights as $lightId => $light) {
        $uniqueId = (string)$light->uniqueid;
        $deviceId = $this->GetDeviceByUniqueId($uniqueId);
        if($deviceId > 0) HUE_ApplyData($deviceId, $light);
      }
    }
  }

  /*
   * HUE_GetDeviceByUniqueId($bridgeId, $uniqueId)
   * Liefert zu einer UniqueID die passende Lampeninstanz
   */
  public function GetDeviceByUniqueId($uniqueId) {
    $deviceIds = IPS_GetInstanceListByModuleID($this->DeviceGuid());
    foreach($deviceIds as $deviceId) {
      if(IPS_GetProperty($deviceId, 'UniqueId') == $uniqueId) {
        return $deviceId;
      }
    }
  }

  private function DeviceGuid() {
    return "{729BE8EB-6624-4C6B-B9E5-6E09482A3E36}";
  }

}
