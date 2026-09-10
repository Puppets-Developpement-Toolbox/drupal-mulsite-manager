<?php

// CU-869dfgrx2 - PROD : Souci avec indexation des robots.txt - ALL SITES
// Construction de la map de redirections
// Nouveau paramètre array &$redirects sur multisite_manager_feed_site()
// Dans la même boucle glob(), collecte les previous_domains de chaque site
// Filtre final : un domaine activement attribué gagne toujours sur une entrée historique


function multisite_manager_feed_site(array &$sites, array &$redirects, string $private_path)
{
  $load_unpublished_replica = !!($_ENV["LOAD_UNPUBLISHED_REPLICA"] ?? false);
  // Redirections vers un domaine de production externe : le domaine source
  // reste activement attribué au site, donc gardées à part du garde-fou
  // ci-dessous (qui ne s'applique qu'aux redirections d'anciens domaines).
  $productionRedirects = [];
  foreach (glob("{$private_path}/multisite_manager/sites/*.php") as $file) {
    $state = require $file;
    if (
      (is_array($state) && $state["published"]) ||
      ($load_unpublished_replica && $state["cloned"])
    ) {
      $id = pathinfo($file, PATHINFO_FILENAME);
      $domain = $state["domain"];

      if($domain !== $_ENV["BLUEPRINT_URI"] && $domain !== $_ENV["FACTORY_URI"] ) {
        $sites[$domain] = "site/{$id}";
      }
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

    // Domaine de production externe (ex: site sandbox hébergé ici, mais dont la
    // marque a un domaine de prod séparé, potentiellement hors de ce serveur).
    if (
      is_array($state) &&
      !empty($state["domain"]) &&
      !empty($state["production_domain"]) &&
      $state["production_domain"] !== $state["domain"]
    ) {
      $productionRedirects[$state["domain"]] = $state["production_domain"];
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

  // Les redirections vers un domaine de production sont ajoutées après le
  // garde-fou : leur domaine source reste volontairement actif dans $sites.
  $redirects = array_merge($redirects, $productionRedirects);
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
      $user !== $state["http_auth"]["username"] &&
      $pass !== $state["http_auth"]["password"]
    ) {
      header('WWW-Authenticate: Basic realm="Authentification required"');
      header("HTTP/1.0 401 Unauthorized");
      echo "You need to authenticate to access this site.";
      exit();
    }
  }
}
