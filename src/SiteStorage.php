<?php

// CU-869dfgrx2 - PROD : Souci avec indexation des robots.txt - ALL SITES
// Détection automatique du changement
// Dans save(), avant d'écraser le domaine actuel, compare l'ancien au nouveau et appelle recordDomainChange() si ça a changé


namespace Drupal\multisite_manager;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SiteStorage extends SqlContentEntityStorage
{
  public static function createInstance(
    ContainerInterface $container,
    EntityTypeInterface $entity_type,
  ) {
    return new static(
      $entity_type,
      $container->get("database"),
      $container->get("entity_field.manager"),
      $container->get("cache.entity"),
      $container->get("language_manager"),
      $container->get("entity.memory_cache"),
      $container->get("entity_type.bundle.info"),
      $container->get("entity_type.manager"),
      $container->get("logger.channel.multisite_manager"),
      $container->get("file_system"),
      Settings::get("multisite_manager_blueprint_uri"),
      $container->get("multisite_manager.site_cloning_queue_manager"),
    );
  }

  public function __construct(
    EntityTypeInterface $entity_type,
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager,
    MemoryCacheInterface $memory_cache,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entity_type_manager,
    private LoggerChannelInterface $logger,
    private FileSystemInterface $fileSystem,
    private string $blueprintUri,
    private SiteCloningQueueManager $queueManager,
  ) {
    parent::__construct(
      $entity_type,
      $database,
      $entity_field_manager,
      $cache,
      $language_manager,
      $memory_cache,
      $entity_type_bundle_info,
      $entity_type_manager,
    );
  }

  /**
   * @param SiteInterface $site
   */
  public function save(EntityInterface $site)
  {
    $result = parent::save($site);
    $currentState = SiteState::loadState($site, $this->fileSystem);

    // Détecter un changement de domaine et mémoriser l'ancien pour la redirection 301.
    // On compare au domaine du fichier d'état (pas $entity->original) car c'est
    // lui qui fait foi pour le routage — cohérent avec le pattern déjà utilisé
    // pour "published" juste en dessous.
    $oldDomain = $currentState->domain;
    $newDomain = $site->domain();
    if ($oldDomain !== $newDomain && $oldDomain !== '') {
      $currentState->recordDomainChange($oldDomain);
    }
    $currentState->domain = $newDomain;
    $currentState->productionDomain = $site->productionDomain();
    if ($site->hasHttpAuth()) {
      $currentState->httpAuth = [
        "username" => $site->get("http_auth_username")->value,
        "password" => $site->get("http_auth_password")->value,
      ];
    } else {
      $currentState->httpAuth = null;
    }

    // if entity and state aren't sync
    if ($site->published() !== $currentState->published) {
      if ($site->published()) {
        $currentState->published = true;
        if (!$currentState->cloned) {
          $this->queueManager->queueSiteCloning($site);
        }
      } else {
        $currentState->published = false;
      }
    }

    $currentState->write();
    return $result;
  }

  public function delete(array $entities)
  {
    parent::delete($entities);
    foreach ($entities as $site) {
      $this->deleteSite($site);
    }
  }

  public static function createSiteDirectoryOperation(
    SiteInterface $site,
    &$context,
  ) {
    /** @var FileSystemInterface */
    $fileSystem = \Drupal::service("file_system");
    // create site directory
    $appRoot = Drupal::root() . "/..";
    $sitePath = "{$appRoot}/web/sites/site/{$site->id()}";
    $fileSystem->prepareDirectory(
      $sitePath,
      FileSystemInterface::CREATE_DIRECTORY,
    );
    $fileSystem->saveData(
      Settings::get("multisite_manager_settings_template"),
      "{$appRoot}/web/sites/site/{$site->id()}/settings.php",
    );
    \Drupal::logger("multisite_manager")->info("settings.php created");
    $context["message"] = t("Created site directory and settings file");
  }

  public static function createDatabaseOperation(SiteInterface $site, &$context)
  {
    // create database
    self::drush($site->domain(), ["sql:create"]);
    \Drupal::logger("multisite_manager")->info("database created");
    $context["message"] = t("database created");
  }

  public static function dumpBlueprintDatabaseOperation(
    SiteInterface $site,
    &$context,
  ) {
    ini_set("time_limit", "600");
    // copy database
    $dumpFile =
      \Drupal::service("file_system")->getTempDirectory() .
      "/{$site->id()}.sql";
    self::drush(Settings::get("multisite_manager_blueprint_uri"), [
      "sql:dump",
      "--result-file={$dumpFile}",
      "--structure-tables-list=cache,cache_*,search_index,watchdog",
    ]);
    \Drupal::logger("multisite_manager")->info("dump created");
    $context["message"] = t("dump created");
  }

  public static function importDatabaseOperation(SiteInterface $site, &$context)
  {
    ini_set("time_limit", "600");
    // copy database
    $dumpFile =
      \Drupal::service("file_system")->getTempDirectory() .
      "/{$site->id()}.sql";
    self::drush($site->domain(), ["sql:query", "--file={$dumpFile}"]);

    /** @var FileSystemInterface */
    $fileSystem = \Drupal::service("file_system");
    $fileSystem->unlink($dumpFile);
    \Drupal::logger("multisite_manager")->info("dump imported");
    $context["message"] = t("dump imported");
  }

  public static function copyPrivateFolderOperation(
    SiteInterface $site,
    &$context,
  ) {
    ini_set("time_limit", "600");
    // copy private folder
    $privatePath = self::getSetting($site->domain(), "file_private_path");
    $blueprintPrivatePath = self::getSetting(
      Settings::get("multisite_manager_blueprint_uri"),
      "file_private_path",
    );
    try {
      if (is_dir($blueprintPrivatePath)) {
        $sfFileSystem = new Filesystem();
        $sfFileSystem->mirror($blueprintPrivatePath, $privatePath);
      } else {
        /** @var FileSystemInterface */
        $fileSystem = \Drupal::service("file_system");
        $fileSystem->prepareDirectory(
          $privatePath,
          FileSystemInterface::CREATE_DIRECTORY,
        );
      }
      \Drupal::logger("multisite_manager")->info("private folder created");
      $context["message"] = t("private folder created");
    } catch (\Throwable $e) {
      if(is_dir($privatePath)) {
        // clean created files
        $fileSystem->remove($privatePath);
      }
      throw $e;
    }
  }

  public static function copyPublicFolderOperation(
    SiteInterface $site,
    &$context,
  ) {
    ini_set("time_limit", "600");
    $appRoot = Drupal::root() . "/..";
    // copy public folder
    $publicPath =
      "{$appRoot}/web/" . self::getSetting($site->domain(), "file_assets_path");
    $blueprintPublicPath =
      "{$appRoot}/web/" .
      self::getSetting(
        Settings::get("multisite_manager_blueprint_uri"),
        "file_assets_path",
      );
    if (is_dir($blueprintPublicPath)) {
      $sfFileSystem = new Filesystem();
      $sfFileSystem->mirror($blueprintPublicPath, $publicPath);
    } else {
      /** @var FileSystemInterface */
      $fileSystem = \Drupal::service("file_system");
      $fileSystem->prepareDirectory(
        $publicPath,
        FileSystemInterface::CREATE_DIRECTORY,
      );
    }
    \Drupal::logger("multisite_manager")->info("public folder created");
    $context["message"] = t("public folder created");
  }

  public static function deleteUsersOperation(SiteInterface $site, &$context)
  {
    // if oauth is enabled, let it manage user
    $users = self::drush($site->domain(), [
      "sql:query",
      "SELECT GROUP_CONCAT(uid) FROM users where uid > 1",
    ]);
    if($users !== 'NULL') {
      \Drupal::logger("multisite_manager")->info(
        "Delete users {$users} and reassign contents",
      );
      self::drush($site->domain(), [
        "user:cancel",
        "--uid={$users}",
        "--reassign-content",
      ]);
      \Drupal::logger("multisite_manager")->info("Users deleted");
    } else {
      \Drupal::logger("multisite_manager")->info("No user to delete");
    }
    $context["message"] = t("Users deleted");
  }

  public static function rebuildCacheOperation(SiteInterface $site, &$context)
  {
    self::drush($site->domain(), ["cache:rebuild"]);
    \Drupal::logger("multisite_manager")->info("cache cleared");
    $context["message"] = t("cache cleared");
    $context["results"]["login_url"] = "https://{$site->domain()}/user/login";
  }

  private function deleteSite(SiteInterface $site)
  {
    // delete site directory
    $currentState = SiteState::loadState($site, $this->fileSystem);

    // copy private folder
    $privatePath = self::getSetting($site->domain(), "file_private_path");
    if ($privatePath && is_dir($privatePath)) {
      $this->fileSystem->deleteRecursive($privatePath);
    }
    \Drupal::logger("multisite_manager")->info("private folder deleted");

    // copy public folder
    $publicRelativePath = self::getSetting($site->domain(), "file_assets_path");
    $appRoot = Drupal::root() . "/..";
    $publicPath = "{$appRoot}/web/{$publicRelativePath}";
    if ($publicRelativePath && is_dir($publicPath)) {
      $this->fileSystem->deleteRecursive($publicPath);
    }
    \Drupal::logger("multisite_manager")->info("public folder deleted");

    // drop database
    try {
      $database = self::drush($site->domain(), [
        "sql:query",
        "SELECT DATABASE();",
      ]);
      self::drush($site->domain(), ["sql:query", "DROP DATABASE {$database}"]);
    } catch (ProcessFailedException $e) {
    }
    \Drupal::logger("multisite_manager")->info("database deleted");

    // delete site directory
    $sitePath = "{$appRoot}/web/sites/site/{$site->id()}";
    if (is_dir($sitePath)) {
      $this->fileSystem->deleteRecursive($sitePath);
    }
    \Drupal::logger("multisite_manager")->info("settings.php deleted");

    // delete site directory
    $currentState->delete();
    \Drupal::logger("multisite_manager")->info("delete site state");
  }

  private static function getSetting(string $domain, string $setting): string
  {
    return self::drush($domain, [
      "php:eval",
      sprintf('print_r(\Drupal\Core\Site\Settings::get("%s"));', $setting),
    ]);
  }

  private static function drush(string $domain, array $cmd): string
  {
    $appRoot = Drupal::root() . "/..";
    $cmd = array_merge(["./vendor/bin/drush", "--uri", $domain, "--yes"], $cmd);
    $env = [
      // drush need an home directory, we just give a fake one
      "HOME" => "/dummy",
      // load unpublished site to manage them
      "LOAD_UNPUBLISHED_REPLICA" => "1",
    ];
    \Drupal::logger("multisite_manager")->debug(
      "run command : " . implode(" ", $cmd),
    );
    $process = new Process($cmd, $appRoot, $env);
    $process->setTimeout(5 * 60);
    $process->mustRun();
    \Drupal::logger("multisite_manager")->debug(
      "command ouput : " . $process->getOutput(),
    );
    return trim($process->getOutput());
  }
}
