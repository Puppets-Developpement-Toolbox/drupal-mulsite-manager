<?php


// list all sites states
function multisite_manager_list_sites(string $private_path): array
{
  $sites = [];
  $load_uncloned = !!($_ENV["LOAD_UNCLONED_SITES"] ?? false);
  $load_unpublished = $load_uncloned || !!($_ENV["LOAD_UNPUBLISHED_REPLICA"] ?? false);
  foreach (glob("{$private_path}/multisite_manager/sites/*.php") as $file) {
    $state = require $file;
    if(
      is_array($state) && 
      ($state["cloned"] || $load_uncloned) &&
      ($state["published"] || $load_unpublished) 
    ) {
      $id = pathinfo($file, PATHINFO_FILENAME);
      $sites[$id] = $state;
    }
  }
  return $sites;
}

/**
 * 
 */
function multisite_manager_feed_site(array &$sites, string $private_path)
{
  $redirects = [];
  $site_states = multisite_manager_list_sites($private_path);

  foreach ($site_states as $id => $state) {
    $domain = $state["domain"];

    if($domain !== $_ENV["BLUEPRINT_URI"] && $domain !== $_ENV["FACTORY_URI"] ) {
      $sites[str_replace('/', '.', $domain)] = "site/{$id}";
    }

    // On collecte les anciens domaines même pour les sites dépubliés :
    // une maintenance temporaire ne doit pas faire disparaître la redirection.
    if (is_array($state) && !empty($state["domain"]) && !empty($state["previous_domains"])) {
      foreach ($state["previous_domains"] as $oldDomain) {
        // Chaque ancien domaine pointe vers le domaine ACTUEL du fichier d'état,
        // relu à chaque requête — donc même après plusieurs changements successifs
        // (A→B→C), tous les anciens domaines pointent directement vers C sans chaîne.
        $redirects[$oldDomain] = $state["domain"];
      }
    }
  }

  // Garde-fou : un domaine activement attribué à un site (présent dans $sites,
  // ou réservé à blueprint/factory) gagne toujours sur une redirection historique.
  // Couvre le cas où un ancien domaine sandbox est réutilisé par un nouveau site.
  foreach (array_keys($redirects) as $from) {
    if (
      isset($sites[$from]) ||
      $from === $_ENV["BLUEPRINT_URI"] ||
      $from === ($_ENV["FACTORY_URI"] ?? null)
    ) {
      unset($redirects[$from]);
    }
  }

  // Rediriger en 301 les anciens domaines (ex: sandbox → prod).
  // Ce bloc s'exécute avant que Drupal ne sélectionne un site, donc avant tout
  // bootstrap — même pattern que multisite_manager_check_http_auth().
  if (php_sapi_name() !== 'cli') {
    $host = strtolower(explode(':', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '')[0]);
    $uri = $_SERVER['REQUEST_URI'];
    $url = "{$host}{$uri}";
    $drupal_site_uri = strtolower(str_replace('/', '.', $url));
    if (array_find_key($sites, fn($site, $key) => str_starts_with($drupal_site_uri, strtolower($key))) === null) {
      foreach($redirects as $from => $to) {
        if (str_starts_with(strtolower($url), strtolower($from))) {
          $path = substr($url, strlen($from));
          header("Location: https://{$to}{$path}", true, 301);
          exit();
        }
      }
    }
  }
}

/**
 * Exclut un site des moteurs de recherche : en-tête
 * "X-Robots-Tag: noindex, nofollow" sur toutes les réponses, dans trois cas :
 * - case « No indexing in search engines » cochée, quel que soit
 *   l'environnement (prod comprise : site public mais non indexé) ;
 * - sandbox (authentification HTTP) ;
 * - environnement autre que la prod (préprod, local).
 * En prod, un site sans case cochée ni authentification HTTP n'est pas concerné.
 *
 * robots.txt n'est volontairement pas bloqué : un "Disallow: /" empêcherait
 * les robots de lire le noindex, et les URL resteraient indexables (sans
 * contenu) à partir de liens externes.
 *
 * À appeler dans le settings.php du projet, après
 * multisite_manager_check_http_auth().
 */
function multisite_manager_lock_robots(
  string $site_id,
  string $private_path,
): void {
  if (php_sapi_name() === "cli") return;

  $state_path = "{$private_path}/multisite_manager/sites/{$site_id}.php";
  $state = file_exists($state_path) ? require $state_path : [];

  if (
    !empty($state["noindex"]) ||
    !empty($state["http_auth"]) ||
    ($_ENV["APP_ENV"] ?? "prod") !== "prod"
  ) {
    header("X-Robots-Tag: noindex, nofollow");
  }
}

function multisite_manager_check_http_auth(
  string $site_id,
  string $private_path,
) {
  $state_path = "{$private_path}/multisite_manager/sites/{$site_id}.php";

  if (!file_exists($state_path)) {
    return;
  }
  if (php_sapi_name() === "cli") {
    return;
  }

  $default_state = [
    "domain" => null,
    "published" => false,
    "cloned" => false,
    "http_auth" => null,
  ];
  $state = array_merge($default_state, require $state_path);

  if (!empty($state["http_auth"])) {
    $user = $_SERVER["PHP_AUTH_USER"] ?? null;
    $pass = $_SERVER["PHP_AUTH_PW"] ?? null;
    if (
      $user !== $state["http_auth"]["username"] ||
      $pass !== $state["http_auth"]["password"]
    ) {
      header('WWW-Authenticate: Basic realm="Authentification required"');
      header("HTTP/1.0 401 Unauthorized");
      echo "You need to authenticate to access this site.";
      exit();
    }
  }
}
