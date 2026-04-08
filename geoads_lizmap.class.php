<?php

class geoads_lizmap extends geoads_base {

  public function init_message_sender() {
      // On n'utilise pas de "messageSender" ici, on fait du cURL direct.
      $this->messageSender = null;
  }

  public function __construct(array $collectivite, array $extra_params = array()) {
    parent::__construct($collectivite, $extra_params);
    
    if($this->get_sig_config('url_sig') === null
      || $this->get_sig_config('base_url') === null
      || $this->get_sig_config('lizmap_user') === null
      || $this->get_sig_config('lizmap_password') === null){
      throw new geoads_connector_exception("Configuration Lizmap incomplète : url_sig, base_url, lizmap_user et lizmap_password sont requis.");
    }

    if($this->get_collectivite_parameter('departement') === null
      || $this->get_collectivite_parameter('commune') === null){
      throw new geoads_connector_exception("Configuration de la collectivité incomplète : code_commune et code_departement sont requis.");
    }
    
    $this->code_insee_parcelle = $this->get_collectivite_parameter('departement').$this->get_collectivite_parameter('commune');
    $this->code_insee = substr($this->get_collectivite_parameter('departement'),0,2).$this->get_collectivite_parameter('commune');
    if (strlen($this->code_insee_parcelle) !== 6) {
      throw new geoads_parameter_exception("Code INSEE parcelle invalide : '$this->code_insee_parcelle'");
    }
    if (strlen($this->code_insee) !== 5) {
      throw new geoads_parameter_exception("Code INSEE commune invalide : '$this->code_insee'");

    }
    $this->url_sig   = (string)$this->get_sig_config('url_sig');
    $this->base_url  = rtrim((string)$this->get_sig_config('base_url'), '/');
    $this->lizmap_user      = (string)$this->get_sig_config('lizmap_user');
    $this->lizmap_password  = (string)$this->get_sig_config('lizmap_password');
  }

  private function authUserPwd(): string {
    return $this->lizmap_user . ':' . $this->lizmap_password;
  }

  private function http(string $method, string $path, ?array $jsonBody = null): array {
    if (!function_exists('curl_init')) {
      throw new geoads_connector_exception("PHP cURL extension is not enabled.");
    }

    $url = $this->base_url . $path;
    $ch = curl_init($url);

    if ($ch === false) {
      throw new geoads_connector_exception("Impossible d'initialiser cURL.");
    }

    try {
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
      curl_setopt($ch, CURLOPT_USERPWD, $this->authUserPwd());
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);         // Timeout total : 30s max
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);  // Connexion : 10s max

      if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
      }

      $raw = curl_exec($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      
      if ($raw === false) {
        throw new geoads_connector_exception("HTTP error: " . $err);
      }

      // Gestion d’erreurs HTTP comme OpenADS le recommande
      if ($code >= 400 && $code < 500) {
        throw new geoads_connector_4XX_exception("Lizmap API returned $code: $raw", $code);
      }
      if ($code >= 500) {
        throw new geoads_connector_5XX_exception("Lizmap API returned $code: $raw", $code);
      }

      $decoded = json_decode($raw, true);
      if (!is_array($decoded)) {
        throw new geoads_connector_exception("Invalid JSON from Lizmap: " . $raw);
      }
      return $decoded;
    } finally {
      //Permet de s'assurer que la ressource cURL est libérée même en cas d'exception
      curl_close($ch);
    }
  }

  private function parcelId(array $p): string {
    // openADS fournit prefixe/quartier/section/parcelle.    
    //normalisation des champs pour construire un ID de parcelle cohérent avec ce que Lizmap attend
    //normalisation du prefixe sur 3 caractères (normalement que 0)
    $prefixe  = str_pad((string)($p['prefixe'] ?? ''), 3, '0', STR_PAD_LEFT);

    //normalisation de la session sur deux caractères 
    $section  = strtoupper((string)($p['section'] ?? ''));
    if (!preg_match('/^[A-Z]{1,2}$/', $section)) {
      throw new geoads_parameter_exception("Section cadastrale invalide : '$section'");
    }

    //normalisation du numéro sur 4 caractères
    $numero = str_pad(preg_replace('/\D/', '', (string)($p['parcelle'] ?? '')), 4, '0', STR_PAD_LEFT);
    if ($numero === '' || !preg_match('/^\d{1,4}$/', $numero)) {
      throw new geoads_parameter_exception("Numéro de parcelle invalide : '$numero'");
    }
    return $this->code_insee_parcelle . $prefixe . $section . $numero;
  }

  public function verif_parcelle($parcelles) {
    $parcelles_verif = array();
    
    foreach ($parcelles as $p) {
      $id = $this->parcelId($p);
      // Appel Lizmap : GET /parcelles/<id>
      $r  = $this->http('GET', '/parcelles/' . rawurlencode($id));

      if (!isset($r['parcelles']) || !is_array($r['parcelles'])) {
        throw new geoads_connector_exception("Réponse Lizmap inattendue : clé 'parcelles' absente.");
      }
      // Lizmap renvoie {"parcelles":[{...}]}
      if (isset($r['parcelles'][0])) {
        $parcelles_verif[] = $r['parcelles'][0];
      } else {
        $parcelles_verif[] = array('parcelle' => $id, 'existe' => false);
      }
    }
    return $parcelles_verif;
  }

  private function checkArgs(array $parcelles, string $dossier, bool $allowEmptyParcelle = false): void{
    $dossierVide   = empty(trim($dossier));
    $parcellesVides = empty($parcelles);

    // Cas où les deux sont attendus (allowEmptyParcelle=false)
    if (!$allowEmptyParcelle && $dossierVide && $parcellesVides) {
        throw new geoads_parameter_exception("Dossier et parcelles manquants");
    }

    // Dossier toujours obligatoire
    if ($dossierVide) {
        throw new geoads_parameter_exception("Dossier manquant");
    }

    // Parcelles obligatoires seulement si allowEmptyParcelle=false
    if (!$allowEmptyParcelle && $parcellesVides) {
        throw new geoads_parameter_exception("Parcelles manquantes");
    }
  }

  public function calcul_emprise(array $parcelles, $dossier) {
    // S'il ne s'agit pas d'un ensemble de parcelles ou manque le dossier
   $this->checkArgs($parcelles,$dossier);

    // 1) IDs cadastraux attendus par Lizmap : INSEE + prefix(3) + section(2) + numero(4)
    $ids = array();
    foreach ($parcelles as $p) {
      $ids[] = $this->parcelId($p);
    }

    // 2) Appel Lizmap
    $r = $this->http(
      'POST',
      '/dossiers/' . rawurlencode((string)$dossier) . '/emprise',
      array('parcelles' => $ids)
    );

    // 3) Retour bool attendu par openADS
    $status = $r['emprise']['statut_calcul_emprise'] ?? null;
    if ($status === null) {
      throw new geoads_connector_exception("Réponse Lizmap inattendue (clé emprise/statut_calcul_emprise absente).");
    }

    // Retourne true ou false
    return filter_var($status, FILTER_VALIDATE_BOOLEAN) === true;
  }

  private function lizmap_url(array $params = array()): string {
    $sep = (strpos($base, '?') === false) ? '?' : '&';
    return rtrim($this->url_sig, '&?') . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986); 
  }

  public function redirection_web_emprise(array $parcelles, $dossier) {
    $this->checkArgs($parcelles,$dossier);

    $parcelIds = array();
    foreach ($parcelles as $p) {
      $parcelIds[] = $this->parcelId($p);
    }

    $params = array(
      'openads_action'  => 'redirection_web_emprise',
      'openads_dossier' => $dossier,
    );

    if (!empty($parcelIds)) {
      $params['openads_parcelles'] = implode(',', $parcelIds);
    }

    return $this->lizmap_url($params);
  }

  public function redirection_web(?array $parcelles = null, $dossier = null) {
    //https://docs.lizmap.com/current/en/publish/configuration/permalink.html#zoom-on-an-object-when-opening-the-map
    //layer=parcelle&filter=%22geo_parcelle%22%20%3D%20%27340172000BX0079%27popup=true
    $filterExpr = '"numero"=\'' . str_replace("'", "''", (string)$dossier) . '\'';
    $params = array(
      'openads_action'  => 'redirection_web',
      'dossier' => $dossier,
      'filter'  => $filterExpr,
      'layer' => 'dossiers_openads',
      'popup' => 'true',
    );

   return $this->lizmap_url($params);
  }

  public function calcul_centroide($dossier) {
    $this->checkArgs([], (string)$dossier, true);
    $r = $this->http(
      'POST',
      '/dossiers/' . rawurlencode($dossier) . '/centroide',
      null
    );   

    if (!isset($r['centroide']) || !is_array($r['centroide'])) {
      throw new geoads_connector_exception("Réponse Lizmap inattendue : clé 'centroide' absente.");
    }

    $c = $r['centroide'];
    $statut_centroid = filter_var($c['statut_calcul_centroide'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$statut_centroid) {
      // Si le calcul du centroïde a échoué, on retourne false
      return false;
    }
    // x/y indispensables si statut true
    if (!isset($c['x'], $c['y'])) {
      throw new geoads_connector_exception("Réponse Lizmap invalide : x/y manquants.");
    }
    $centroid_result = array(
      "statut_calcul_centroide" => true,
      "x" => (string)$c['x'],
      "y" => (string)$c['y'],
      "parcelles" => isset($c['parcelles']) ? (string)$c['parcelles'] : '',
      'surface' => isset($c['surface']) ? (float)$c['surface'] : null,
    );    
    return $centroid_result;
  }

  public function recup_toutes_contraintes($code_insee){
    if (empty($this->code_insee)) {
        throw new geoads_parameter_exception("Code INSEE manquant");
    }

    // Appel Lizmap : /communes/<insee>/contraintes
    $r = $this->http(
        'GET',
        '/communes/' . rawurlencode($this->code_insee) . '/contraintes'
    );

    if (!isset($r['contraintes']) || !is_array($r['contraintes'])) {
        throw new geoads_connector_exception("Réponse Lizmap inattendue : clé 'contraintes' absente.");
    }

    // Normalisation (même structure que recup_contrainte_dossier)
    $out = array();
    foreach ($r['contraintes'] as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (!isset($c['contrainte'], $c['groupe_contrainte'], $c['sous_groupe_contrainte'], $c['libelle'])) {
            continue;
        }

        $row = array(
            'contrainte'             => (string)$c['contrainte'],
            'groupe_contrainte'      => (string)$c['groupe_contrainte'],
            'sous_groupe_contrainte' => (string)$c['sous_groupe_contrainte'],
            'libelle'                => (string)$c['libelle'],
        );

        if (array_key_exists('texte', $c) && $c['texte'] !== null && $c['texte'] !== '') {
            $row['texte'] = (string)$c['texte'];
        }
        $out[] = $row;
    }
    return $out;
  }

  public function recup_contrainte_dossier($dossier) {
    $this->checkArgs([], (string)$dossier, true);
    
    $r = $this->http(
      'GET',
      '/dossiers/' . rawurlencode($dossier) . '/contraintes',
      null
    );
    

    if (!isset($r['contraintes']) || !is_array($r['contraintes'])) {
      throw new geoads_connector_exception("Réponse Lizmap inattendue : clé 'contraintes' absente.");
    }  

    $out = array();
    foreach ($r['contraintes'] as $c) {
      if (!is_array($c)) {
          continue;
      }

      // Champs requis openADS
      if (!isset($c['contrainte'], $c['groupe_contrainte'], $c['sous_groupe_contrainte'], $c['libelle'])) {
          // Soit tu ignores, soit tu lèves une exception. Ici on ignore proprement.
          continue;
      }

      $row = array(
          'contrainte'            => (string)$c['contrainte'],
          'groupe_contrainte'     => (string)$c['groupe_contrainte'],
          'sous_groupe_contrainte'=> (string)$c['sous_groupe_contrainte'],
          'libelle'               => (string)$c['libelle'],
      );

      // "texte" est optionnel
      if (array_key_exists('texte', $c) && $c['texte'] !== null && $c['texte'] !== '') {
          $row['texte'] = (string)$c['texte'];
      }

      $out[] = $row;
    }
    return $out;
  }
}