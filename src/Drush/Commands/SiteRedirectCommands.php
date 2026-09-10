<?php

// CU-869dfgrx2 - PROD : Souci avec indexation des robots.txt - ALL SITES
//
// ? Backfill des 6 cas historiques
// Commandes drush à lancer sur chaque environnement (préprod, prod) où ces domaines sont configurés.
// La commande utilise les noms de domaine et non les IDs, donc elle s'adapte automatiquement à l'environnement.
// drush site:redirect-add ancien-domaine.com nouveau-domaine.com
// Le nouveau-domaine.com doit être le domaine actuellement actif du site dans cet environnement (sinon la commande répond "Aucun site n'a actuellement le domaine X" et ne fait rien)
//
//
// ! Commandes à appliquer au deploy PP
//# 1. Lister les domaines réels de la préprod
// ./vendor/bin/drush --uri=<URL_FACTORY_PREPROD> site:list
// # 2. Utiliser les vrais domaines préprod dans la commande
// ./vendor/bin/drush --uri=<URL_FACTORY_PREPROD> site:redirect-add  <ancien-domaine-replica-en-preprod> <nouveau-domaine-replica-en-preprod>
// Soit :
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add sdp.sandbox.mediaschool.eu supdeprod.com
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add mss.sandbox.mediaschool.eu mediaschool-sports.com
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add ets.sandbox.mediaschool.eu ecole-europeenne.com
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add iris.sandbox.mediaschool.eu ecoleiris.fr
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add pstc.sandbox.mediaschool.eu ecole-pstc.fr
// ./vendor/bin/drush --uri=https://factory.preprod.mediaschool.syazen.cloud site:redirect-add paris-bts.sandbox.mediaschool.eu paris-bts.com


//
// ? Liste à éventuellement compléter suite au résultat de ./vendor/bin/drush site:list
//

namespace Drupal\multisite_manager\Drush\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\multisite_manager\SiteState;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Commandes Drush pour gérer les redirections de domaine des sites.
 */
final class SiteRedirectCommands extends DrushCommands {

  public function __construct(private FileSystemInterface $fileSystem) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('file_system'));
  }

  /**
   * Enregistre une redirection 301 pour un changement de domaine déjà effectué.
   *
   * Utile pour les sites dont le domaine a changé avant la mise en place
   * du mécanisme automatique. Ne rien coder en dur : relancer la commande
   * avec les bons domaines de chaque environnement (local, préprod, prod).
   *
   * Exemple d'utilisation pour les 6 cas connus :
   *   drush site:redirect-add sdp.sandbox.mediaschool.eu supdeprod.com
   *   drush site:redirect-add mss.sandbox.mediaschool.eu mediaschool-sports.com
   *   (etc.)
   */
  #[CLI\Command(name: 'site:redirect-add', aliases: ['sra'])]
  #[CLI\Argument(name: 'old_domain', description: 'Ancien domaine à rediriger (ex: sdp.sandbox.mediaschool.eu)')]
  #[CLI\Argument(name: 'new_domain', description: 'Domaine actuel du site cible (ex: supdeprod.com)')]
  #[CLI\Usage(name: 'drush site:redirect-add sdp.sandbox.mediaschool.eu supdeprod.com', description: "Redirige l'ancien domaine sandbox vers le domaine de production actuel.")]
  public function addRedirect(string $old_domain, string $new_domain): void
  {
    if ($old_domain === $new_domain) {
      $this->io()->error("L'ancien et le nouveau domaine sont identiques.");
      return;
    }

    $sites = \Drupal::entityTypeManager()
      ->getStorage('multisite_manager_site')
      ->loadByProperties(['domain' => $new_domain]);
    $site = reset($sites);

    if (!$site) {
      $this->io()->error("Aucun site n'a actuellement le domaine '{$new_domain}'.");
      return;
    }

    $state = SiteState::loadState($site, $this->fileSystem);
    $state->recordDomainChange($old_domain);
    $state->write();

    $this->io()->success("Redirection enregistrée : {$old_domain} → {$new_domain}.");
  }

}

class_alias(SiteRedirectCommands::class, 'Drush\\SiteRedirectCommands');
