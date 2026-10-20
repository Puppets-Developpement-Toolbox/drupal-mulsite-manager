# Sites servis sous un dossier (`example.com/test`)

Le module accepte un domaine avec dossier (`example.com/test`, voire
`example.com/test/fr`) et l'enregistre dans `$sites` sous la clé
`example.com.test`. Drupal ne sait pas servir ce site tout seul : le projet doit
mettre en place les trois points ci-dessous.

## Pourquoi

`DrupalKernel::findSitePath()` construit la clé de site à partir de l'hôte et du
**chemin du script exécuté** (`SCRIPT_NAME`), pas de l'URL demandée. Comme
Drupal est toujours servi par `/index.php`, la partie dossier de la clé ne
correspond jamais. La doc Drupal (`example.sites.php`) suppose un vrai
sous-dossier (symlink ou `Alias` Apache), ce qui n'est pas envisageable pour des
sites créés depuis le back-office.

## Ce que le module vérifie déjà

À l'enregistrement d'un site (`UniqueDomainNameConstraint`), il refuse :

- deux sites imbriqués (`example.com` et `example.com/test`) : le plus précis
  masquerait une partie de l'autre ;
- un premier dossier qui porte le nom d'un élément de la racine Drupal
  (`core`, `sites`, `modules`…) : Apache servirait le vrai dossier ;
- un dossier qui est un code de langue (`en`, `fr`, `pt-br`…) : il serait lu
  comme un préfixe de langue.

Les sites déjà en base ne sont pas revérifiés.

## 1. `web/sites/sites.php` : faire trouver le site au kernel

`sites.php` est inclus **dans** `findSitePath()` : il peut surcharger les
variables locales `$script_name` et `$request`. À placer après
`multisite_manager_feed_site()` :

```php
// $site_prefix est aussi lu par sites/settings.php (trusted_host_patterns).
$site_prefix = null;
if (php_sapi_name() !== "cli" && $host !== null) {
  $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
  $prefix = "";
  foreach (array_filter(explode("/", $path), "strlen") as $segment) {
    $prefix .= "/{$segment}";
    if (isset($sites[$host . str_replace("/", ".", $prefix)])) {
      $site_prefix = $prefix;
    }
  }
  // Symfony ne détecte le base path qu'à partir de "/test/" : rediriger "/test".
  if ($site_prefix !== null && rtrim($path, "/") === $site_prefix && !str_ends_with($path, "/")) {
    $query = parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY);
    header("Location: {$site_prefix}/" . ($query ? "?{$query}" : ""), true, 301);
    exit();
  }
  if ($site_prefix !== null && isset($script_name, $request)) {
    $script_name = "{$site_prefix}/index.php";
    $request->server->set("SCRIPT_NAME", $script_name);
  }
}
```

Les deux surcharges sont nécessaires :

- `$script_name` : le kernel trouve le bon dossier de site ;
- `SCRIPT_NAME` sur la requête : Symfony en déduit le base path (`/test`). Sans
  elle, le bon site est chargé mais les routes répondent 404 et les liens sont
  générés sans `/test`.

La CLI est exclue : Drush positionne lui-même `SCRIPT_NAME` à partir de `--uri`.

## 2. `web/sites/settings.php` : hôte de confiance

Si `trusted_host_patterns` est construit à partir de `array_keys($sites)`, une
clé `example.com.test` n'est pas un hôte valide et Drupal répond « The provided
host name is not valid ». Après l'inclusion de `sites.php` :

```php
if (!empty($site_prefix)) {
  $settings['trusted_host_patterns'][] = '^' . preg_quote($host) . '$';
}
```

## 3. `.htaccess` : servir les assets sous le dossier

Les CSS, JS, images et fichiers publics sont appelés en `/test/core/…` ou
`/test/sites/<site>/files/…`, chemins qui n'existent pas sur le disque. La règle
suivante les renvoie vers le vrai fichier, quel que soit le dossier, sans
configuration par site :

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{DOCUMENT_ROOT}/$2 -f
RewriteRule ^(.+?)/((?:core|modules|themes|profiles|libraries|sites)/.+)$ /$2 [L]
```

## Vérifier

```bash
H=example.com   # hôte du site servi sous /test
curl -sI -H "Host: $H" http://localhost/test             # 301 -> /test/
curl -s  -H "Host: $H" http://localhost/test/ | grep -o 'href="/test/[^"]*"' | head
curl -sI -H "Host: $H" http://localhost/test/user/login  # 200
curl -sI -H "Host: $H" http://localhost/test/core/misc/drupal.js  # 200
drush --uri="$H/test" status                             # bon "Site path"
```

## Limites connues

- Apache uniquement : pour nginx, la règle `.htaccess` est à traduire dans la
  configuration du serveur.