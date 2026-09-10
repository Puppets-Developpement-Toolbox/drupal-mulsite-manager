<?php

namespace Drupal\multisite_manager\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


final class ListSitesCommand extends DrushCommands {

  #[CLI\Command(name: 'site:list', aliases: ['sl'])]
  #[CLI\Option(name: 'no-factory', description: 'Do not return factory')]
  #[CLI\Option(name: 'no-blueprint', description: 'Do not return blueprint')]
  #[CLI\Option(name: 'no-replica', description: 'Do not return replica')]
  #[CLI\Option(name: 'replica', description: 'Do return replica only')]
  public function commandName(InputInterface $input, OutputInterface $output)
  {
    // Deux contextes possibles :
    // 1. Script de déploiement (drush --include sans bootstrap Drupal) :
    //    sites.php n'a pas encore été chargé → include_once fonctionne et
    //    popule $sites dans ce scope.
    // 2. Drush bootstrappé (drush --uri=...) :
    //    sites.php a déjà été chargé par le bootstrap multisite →
    //    include_once est ignoré → on appelle la fonction directement
    //    avec un chemin relatif depuis __DIR__ (pas \Drupal::root() qui
    //    nécessite un container bootstrappé).
    if (function_exists('multisite_manager_feed_site')) {
      $sites = [
        $_ENV['BLUEPRINT_URI'] ?? '' => 'blueprint',
      ];
      if (!empty($_ENV['FACTORY_URI'])) {
        $sites[$_ENV['FACTORY_URI']] = 'factory';
      }
      $redirects = [];
      // __DIR__ = web/modules/custom/multisite_manager/src/Drush/Commands
      // 7 niveaux vers le haut → racine du projet
      $privatePath = __DIR__ . '/../../../../../../../storage/private/factory/';
      multisite_manager_feed_site($sites, $redirects, $privatePath);
    } else {
      $sites = [];
      include_once __DIR__ . '/../../../../../../sites/sites.php';
    }

    $noFactory = $input->getOption('no-factory') || $input->getOption('replica');
    $noBlueprint = $input->getOption('no-blueprint') || $input->getOption('replica');
    if($noFactory) {
      unset($sites[array_search('factory', $sites)]);
    }
    if($noBlueprint) {
      unset($sites[array_search('blueprint', $sites)]);
    }
    if($input->getOption('no-replica')) {
      foreach($sites as $domain => $name) {
        if($name !== 'blueprint' && $name !== 'factory') {
          unset($sites[$domain]);
        }
      }
    }
    $domains = array_keys($sites);
    array_map([$output, 'writeln'], $domains);
  }

}


class_alias(ListSitesCommand::class, 'Drush\\ListSitesCommand');
