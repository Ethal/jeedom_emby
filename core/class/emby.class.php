<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class emby extends eqLogic {

  public function updateUser() {
    if (substr(config::byKey('username','emby'),0,1) != '+') {
      log::add('emby', 'error', 'Nom utilisateur mal formé, vous devez saisir +33...');
      return;
    }
    emby::authCloud();
    $url = 'utilisateur/get/' . urlencode(config::byKey('username','emby'));
    $json = emby::callCloud($url);
    foreach ($json['data']['serrures'] as $key) {
      $emby = emby::byLogicalId($key['id_serrure'], 'emby');
      if (!is_object($emby)) {
        $emby = new emby();
        $emby->setEqType_name('emby');
        $emby->setLogicalId($key['id_serrure']);
        $emby->setName('Serrure ' . $key['nom']);
        $emby->setIsEnable(1);
        $emby->setConfiguration('type', 'locker');
        $emby->setConfiguration('id', $key['id']);
        $emby->setConfiguration('id_serrure', $key['id_serrure']);
        $emby->setConfiguration('code', $key['code']);
        $emby->setConfiguration('code_serrure', $key['code_serrure']);
        $emby->setConfiguration('serrure_droite', $key['serrure_droite']);
        //$emby->setConfiguration('etat', $key['etat']);
        $emby->setConfiguration('couleur', $key['couleur']);
        $emby->setConfiguration('public_key', $key['public_key']);
        $emby->setConfiguration('nom', $key['nom']);
        //$emby->setConfiguration('battery', $key['battery']);
        $emby->save();
        event::add('emby::found', array(
          'message' => __('Nouvelle serrure ' . $key['nom'], __FILE__),
        ));
      }
      $embyCmd = embyCmd::byEqLogicIdAndLogicalId($emby->getId(),'status');
      if (!is_object($embyCmd)) {
        $emby->loadCmdFromConf($emby->getConfiguration('type'));
      }
      $value = ($key['etat'] == 'open') ? 0:1;
      $emby->checkAndUpdateCmd('status',$value);
      $emby->checkAndUpdateCmd('battery',$key['battery']/1000);
      $emby->batteryStatus($key['battery']/40);
      log::add('emby', 'debug', 'Serrure ' . $key['nom'] . ' statut ' . $key['etat'] . ' ' . $value . ' batterie ' . $key['battery']);
    }
  }

  public function scanLockers() {
    if (!$this->pingHost()) {
      log::add('emby', 'debug', 'Erreur de connexion gateway');
      return;
    }
    $key = config::byKey('shares_accessoire','emby');
    $idgateway = $this->getConfiguration('idfield');
    $url = 'http://' . $this->getConfiguration('ipfield') . '/lockers';
    $request_http = new com_http($url);
    $output = $request_http->exec(30);
    log::add('emby', 'debug', 'Scan : ' . $output);
    $json = json_decode($output, true);
    log::add('emby', 'debug', 'Scan : ' . $url);
    foreach ($json['devices'] as $device) {
      $emby = emby::byLogicalId($device['identifier'], 'emby');
      if (is_object($emby)) {
        $emby->setConfiguration('rssi',$device['rssi']);
        $emby->save();
        //createCmds for this gateway
        $emby->checkCmdOk($idgateway, 'open', 'locker', 'Déverrouillage avec ' . $this->getName());
        $emby->checkCmdOk($idgateway, 'close', 'locker', 'Verrouillage avec ' . $this->getName());
        $emby->checkAndUpdateCmd('battery',$device['battery']/1000);
        $emby->batteryStatus($device['battery']/40);
        $code = $key[$idgateway][$emby->getConfiguration('id')]['code'];
        sleep(1);
        $output = $this->callGateway('locker_status',$emby->getConfiguration('id_serrure'),$code);
        $status = ($output['status']== 'Door closed') ? 1 : 0;
        $emby->checkAndUpdateCmd('status',$status);
        log::add('emby', 'debug', 'Rafraichissement serrure : ' . $device['identifier'] . ' ' . $device['battery'] . ' ' . $device['rssi']);
      }
    }
    $url = 'http://' . $this->getConfiguration('ipfield') . '/synchronize';
    $request_http = new com_http($url);
    $output = $request_http->exec(30);
    log::add('emby', 'debug', 'Synchronise : ' . $url . ' ' . $output);
  }

  public function cmdsShare() {
    foreach (eqLogic::byType('emby', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'locker') {
        $this->checkCmdOk($keyeq->getLogicalId(), 'enable', $this->getConfiguration('type'), 'Activer partage avec ' . $keyeq->getName());
        $this->checkCmdOk($keyeq->getLogicalId(), 'unable', $this->getConfiguration('type'), 'Désactiver partage avec ' . $keyeq->getName());
        $this->checkCmdOk($keyeq->getLogicalId(), 'status', $this->getConfiguration('type'), 'Statut partage avec ' . $keyeq->getName());
      }
    }
  }

  public function checkShare() {
    if (substr(config::byKey('username','emby'),0,1) != '+') {
      return;
    }
    emby::authCloud();
    $accessoire = array();
    $phone = array();
    foreach (eqLogic::byType('emby', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'gateway') {
        $accessoire[$keyeq->getConfiguration('idfield')] = array();
      }
      if ($keyeq->getConfiguration('type') == 'phone') {
        $phone[$keyeq->getConfiguration('idfield')] = array();
      }
      if ($keyeq->getConfiguration('type') == 'button') {
        $accessoire[$keyeq->getConfiguration('idfield')] = array();
      }
    }
    log::add('emby', 'debug', 'Accessoire : ' . print_r($accessoire,true));
    foreach (eqLogic::byType('emby', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'locker') {
        $url = 'partage/all/serrure/' . $keyeq->getConfiguration('id');
        $json = emby::callCloud($url);
        foreach ($json['data']['partages_accessoire'] as $share) {
          log::add('emby', 'debug', 'Partage serrure : ' . $share['accessoire']['id_accessoire'] . ' ' . $share['code']);
          if (!(isset($share['date_debut']) || isset($share['date_fin']) || isset($share['heure_debut']) || isset($share['heure_fin']))) {
            //on vérifier que c'est un partage permanent, jeedom ne prend pas en compte les autres
            $accessoire[$share['accessoire']['id_accessoire']][$keyeq->getConfiguration('id')]['id'] = $share['id'];
            $accessoire[$share['accessoire']['id_accessoire']][$keyeq->getConfiguration('id')]['code'] = $share['code'];
            //on sauvegarde le statut si bouton/phone, si gateway on s'assure d'etre en actif
            $eqtest = emby::byLogicalId($share['accessoire']['id_accessoire'], 'emby');
            if (is_object($eqtest)) {
              if ($eqtest->getConfiguration('type') == 'gateway' && !$share['actif']) {
                $keyeq->editShare($share['id'], $share['accessoire']['id_accessoire']);
              }
              if ($eqtest->getConfiguration('type') == 'phone' || $eqtest->getConfiguration('type') == 'button') {
                $value = ($share['actif']) ? 1:0;
                $eqtest->checkAndUpdateCmd('status-'.$keyeq->getLogicalId(), $value);
              }
            }
            if ($share['accessoire']['type'] == '2') {
              $eqtest = emby::byLogicalId($share['accessoire']['id_accessoire'] . '-' . $share['id'], 'emby');
              if (!is_object($eqtest)) {
                log::add('emby', 'debug', 'Digicode trouvé');
                $eqtest = new emby();
                $eqtest->setEqType_name('emby');
                $eqtest->setLogicalId($share['accessoire']['id_accessoire'] . '-' . $share['id']);
                $eqtest->setName('Digicode sur ' . $share['accessoire']['nom'] . ' avec ' . $share['code']);
                $eqtest->setIsEnable(1);
                $eqtest->setConfiguration('type', 'digicode');
                $eqtest->setConfiguration('id_share', $share['id']);
                $eqtest->setConfiguration('id_serrure', $keyeq->getLogicalId());
                $eqtest->setConfiguration('id', $share['accessoire']['id_accessoire']);
                $eqtest->setConfiguration('code', $share['code']);
                $eqtest->save();
                $eqtest->checkCmdOk($share['id'], 'enable', 'digicode', 'Activer');
                $eqtest->checkCmdOk($share['id'], 'unable', 'digicode', 'Désactiver');
                $eqtest->checkCmdOk($share['id'], 'status', 'digicode', 'Statut');
                event::add('emby::found', array(
                  'message' => __('Nouveau partage digicode ' . $share['accessoire']['id_accessoire'], __FILE__),
                ));
              }
              log::add('emby', 'debug', 'Digicode satus : ' . $share['actif']);
              $value = ($share['actif']) ? 1:0;
              $eqtest->checkAndUpdateCmd('status-'.$share['id'], $value);
            }
          }
        }
        foreach ($accessoire as $id => $stuff) {
          //boucle pour vérifier si chaque gateway/bouton possède une entrée de partage avec l'équipement en cours, sinon on appelle le createShare et on ajoute le retour
          log::add('emby', 'debug', 'ID : ' . $id . ' ' . print_r($stuff,true));
          if (count($stuff) == 0) {
            log::add('emby', 'debug', 'Create Share : ' . $id . ' ' . print_r($stuff,true));
            $json = $keyeq->createShare($id);
            if (isset($json['data']['code'])) {
              $accessoire[$id]['id'] = $json['data']['id'];
              $accessoire[$id]['code'] = $json['data']['code'];
            }
          }
        }
        foreach ($json['data']['partages_utilisateur'] as $share) {
          log::add('emby', 'debug', 'Partage serrure : ' . $share['utilisateur']['username']);
          if (!(isset($share['date_debut']) || isset($share['date_fin']) || isset($share['heure_debut']) || isset($share['heure_fin']))) {
            //on vérifier que c'est un partage permanent, jeedom ne prend pas en compte les autres
            $phone[$share['utilisateur']['username']][$keyeq->getConfiguration('id')]['id'] = $share['id'];
            //$phone[$share['utilisateur']['username']][$keyeq->getConfiguration('id')]['code'] = $share['code'];
            $eqtest = emby::byLogicalId($share['utilisateur']['username'], 'emby');
            if (is_object($eqtest)) {
              $value = ($share['actif']) ? 1:0;
              $eqtest->checkAndUpdateCmd('status-'.$keyeq->getLogicalId(), $value);
              log::add('emby', 'debug', 'Partage serrure : ' . $share['utilisateur']['username']. 'status-'.$keyeq->getConfiguration('id') . ' ' . $value);
            }
          }
        }
        log::add('emby', 'debug', 'Phones trouvés : ' . print_r($phone,true));
        foreach ($phone as $id => $stuff) {
          //boucle pour vérifier si chaque gateway/bouton possède une entrée de partage avec l'équipement en cours, sinon on appelle le createShare et on ajoute le retour
          log::add('emby', 'debug', 'ID : ' . $id . ' ' . print_r($stuff,true));
          if (count($stuff) == 0) {
            log::add('emby', 'debug', 'Create Share : ' . $id . ' ' . print_r($stuff,true));
            $json = $keyeq->createShare($id,true);
            if (isset($json['data']['code'])) {
              $phone[$id]['id'] = $json['data']['id'];
              $phone[$id]['code'] = $json['data']['code'];
            }
          }
        }
      }
    }
    config::save('shares_accessoire', json_encode($accessoire),  'emby');
    config::save('shares_phone', json_encode($phone),  'emby');
  }

  public function createShare($_id, $_phone = false, $_digicode = '') {
    if (substr(config::byKey('username','emby'),0,1) != '+') {
      return;
    }
    emby::authCloud();
    if ($_phone) {
      $url = 'partage/create/' . $this->getConfiguration('id') . '/' . urlencode($_id);
      $data = array('partage[description]' => 'jeedom', 'partage[nom]' => 'jeedom' . str_replace('+','',$_id), 'partage[actif]' => 1);
    } else {
      $url = 'partage/create/' . $this->getConfiguration('id') . '/accessoire/' . $_id;
      $data = array('partage_accessoire[description]' => 'jeedom', 'partage_accessoire[nom]' => 'jeedom' . str_replace('+','',$_id), 'partage_accessoire[actif]' => 1);
      if ($_digicode != '') {
        $data['partage_accessoire[code]'] = $_digicode;
      }
    }
    $json = emby::callCloud($url,$data);
    return $json;
  }

  public function editShare($_id, $_eqId, $_actif = 'enable', $_phone = false, $_digicode = '') {
    if (substr(config::byKey('username','emby'),0,1) != '+') {
      return;
    }
    emby::authCloud();
    if ($_phone) {
      $url = 'partage/update/' . urlencode($_id);
      $data = array('partage[nom]' => 'jeedom' . str_replace('+','',$_id));
      if ($_actif == 'enable') {
        $data['partage[actif]'] = 1;
      }
    } else {
      $url = 'partage/accessoire/update/' . $_id;
      $data = array('partage_accessoire[nom]' => 'jeedom' . str_replace('+','',$_eqId));
      if ($_actif == 'enable') {
        $data['partage_accessoire[actif]'] = 1;
      }
    }
    if ($_digicode != '') {
      $data['partage_accessoire[code]'] = $_digicode;
    }
    log::add('emby', 'debug', 'ID : ' . $_id . ' ' . $_actif . ' ' . print_r($data,true));
    $json = emby::callCloud($url,$data);
    return $json;
  }

  public function postAjax() {
    if ($this->getConfiguration('type') != 'locker') {
      $this->setConfiguration('type',$this->getConfiguration('typeSelect'));
      $this->setLogicalId($this->getConfiguration('idfield'));
      $this->save();
    }
    if ($this->getConfiguration('type') == 'gateway') {
      $this->loadCmdFromConf($this->getConfiguration('type'));
      $this->save();
      $this->scanLockers();
      event::add('emby::found', array(
        'message' => __('Nouveau gateway ' . $this->getName(), __FILE__),
      ));
    }
    if ($this->getConfiguration('type') == 'button' || $this->getConfiguration('type') == 'phone') {
      $this->cmdsShare();
    }
    self::updateUser();
    self::checkShare();
  }

  public function loadCmdFromConf($type) {
    if (!is_file(dirname(__FILE__) . '/../config/devices/' . $type . '.json')) {
      return;
    }
    $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $type . '.json');
    if (!is_json($content)) {
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      return true;
    }
    $this->import($device);
  }

  public function checkCmdOk($_id, $_value, $_category, $_name) {
    $embyCmd = embyCmd::byEqLogicIdAndLogicalId($this->getId(),$_value . '-' . $_id);
    if (!is_object($embyCmd)) {
      log::add('emby', 'debug', 'Création de la commande ' . $_value . '-' . $_id);
      $embyCmd = new embyCmd();
      $embyCmd->setName(__($_name, __FILE__));
      $embyCmd->setEqLogic_id($this->getId());
      $embyCmd->setEqType('emby');
      $embyCmd->setLogicalId($_value . '-' . $_id);
      if ($_value == 'status') {
        $embyCmd->setType('info');
        $embyCmd->setSubType('binary');
        $embyCmd->setTemplate("mobile",'lock' );
        $embyCmd->setTemplate("dashboard",'lock' );
      } else {
        $embyCmd->setType('action');
        $embyCmd->setSubType('other');
        if ($_value == 'open' || $_value == 'enable') {
          $embyCmd->setDisplay("icon",'<i class="fa fa-unlock"></i>' );
        } else {
          $embyCmd->setDisplay("icon",'<i class="fa fa-lock"></i>' );
        }
      }
      $embyCmd->setConfiguration('value', $_value);
      $embyCmd->setConfiguration('id', $_id);
      $embyCmd->setConfiguration('category', $_category);
      if ($_category == 'locker') {
        $embyCmd->setConfiguration('gateway', $_id);
      }
      $embyCmd->save();
    }
  }

  public function pingHost () {
    $connection = @fsockopen($this->getConfiguration('ipfield'), 80);
    if (is_resource($connection)) {
      $result = true;
      $this->checkAndUpdateCmd('online', 1);
    } else {
      $result = false;
      $this->checkAndUpdateCmd('online', 0);
    }
    return $result;
  }

  

}

class embyCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getType() == 'info') {
      return;
    }
    switch ($this->getConfiguration('category')) {
      case 'locker' :
      $eqLogic = $this->getEqLogic();
      $gatewayid = $this->getConfiguration('gateway');
      $gateway = emby::byLogicalId($gatewayid, 'emby');
      $key = config::byKey('shares_accessoire','emby');
      //log::add('emby', 'debug', 'Config : ' . print_r(config::byKey('shares_accessoire','emby'),true));
      $code = $key[$gatewayid][$eqLogic->getConfiguration('id')]['code'];
      if (is_object($gateway)) {
        $gateway->callGateway($this->getConfiguration('value'),$eqLogic->getConfiguration('id_serrure'),$code);
        sleep(1);
        $gateway->scanLockers();
      } else {
        log::add('emby', 'debug', 'Gateway non existante : ' . $gatewayid);
      }
      log::add('emby', 'debug', 'Commande : ' . $this->getConfiguration('value') . ' ' . $eqLogic->getConfiguration('id_serrure') . ' ' . $code);
      break;
      case 'gateway' :
      $eqLogic = $this->getEqLogic();
      emby::updateUser();
      emby::checkShare();
      $eqLogic->scanLockers();
      break;
      case 'digicode' :
      $eqLogic = $this->getEqLogic();
      $locker = emby::byLogicalId($eqLogic->getConfiguration('id_serrure'), 'emby');
      $locker->editShare($eqLogic->getConfiguration('id_share'), $eqLogic->getConfiguration('id') . '-' . $eqLogic->getConfiguration('code'), $this->getConfiguration('value'), false);
      emby::updateUser();
      emby::checkShare();
      break;
      default :
      $eqLogic = $this->getEqLogic();
      if ($this->getConfiguration('category') == 'phone') {
        $key = config::byKey('shares_phone','emby');
        $phone = true;
      } else {
        $key = config::byKey('shares_accessoire','emby');
        $phone = false;
      }
      $locker = emby::byLogicalId($this->getConfiguration('id'), 'emby');
      $id = $key[$eqLogic->getLogicalId()][$locker->getConfiguration('id')]['id'];
      log::add('emby', 'debug', 'Config : ' . $eqLogic->getLogicalId() . ' ' . $locker->getConfiguration('id') . ' ' . print_r(config::byKey('shares_accessoire','emby'),true));
      $locker->editShare($id, $eqLogic->getLogicalId(), $this->getConfiguration('value'), $phone);
      emby::updateUser();
      emby::checkShare();
      break;
    }
  }
}

?>
