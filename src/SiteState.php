<?php

// CU-869dfgrx2 - PROD : Souci avec indexation des robots.txt - ALL SITES
// Mémoire des anciens domaines
// Propriété $previousDomains + persistée dans le fichier d'état
// Méthode recordDomainChange() pour y ajouter un domaine (avec dédoublonnage)


namespace Drupal\multisite_manager;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

class SiteState
{
  static $STATES = [];

  public string $domain;
  public bool $published;
  public bool $cloned;
  public string $error_message;
  public ?array $httpAuth;
  public array $previousDomains;
  public string $productionDomain;

  static function loadState(
    SiteInterface $site,
    FileSystemInterface $fileSystem,
  ): self {
    if (!isset(self::$STATES[$site->id()])) {
      self::$STATES[$site->id()] = new self($site, $fileSystem);
    }
    return self::$STATES[$site->id()];
  }

  private function __construct(
    private SiteInterface $site,
    private FileSystemInterface $fileSystem,
  ) {
    $this->load();
  }

  private function path()
  {
    return "private://multisite_manager/sites/{$this->site->id()}.php";
  }

  private function load()
  {
    $realpath = $this->fileSystem->realpath($this->path());
    $state = [
      "domain" => $this->site->domain(),
      "published" => false,
      "cloned" => false,
      "http_auth" => null,
      "previous_domains" => [],
      "production_domain" => "",
    ];
    if (file_exists($realpath)) {
      $state = array_merge($state, include $realpath);
    }
    $this->domain = $state["domain"];
    $this->published = $state["published"];
    $this->cloned = $state["cloned"];
    $this->error_message = $state["error_message"] ?? "";
    $this->httpAuth = $state["http_auth"];
    $this->previousDomains = $state["previous_domains"];
    $this->productionDomain = $state["production_domain"];
  }

  public function write()
  {
    // register domain
    $path = "private://multisite_manager/sites";
    $this->fileSystem->prepareDirectory(
      $path,
      FileSystemInterface::CREATE_DIRECTORY,
    );
    $content =
      "<?php return " .
      var_export(
        [
          "domain" => $this->domain,
          "cloned" => $this->cloned,
          "published" => $this->published,
          "http_auth" => $this->httpAuth,
          "error_message" => $this->error_message,
          "previous_domains" => $this->previousDomains,
          "production_domain" => $this->productionDomain,
        ],
        true,
      ) .
      ";";
    $this->fileSystem->saveData($content, $this->path(), FileExists::Replace);

    // clear opcache
    if (function_exists("opcache_reset")) {
      opcache_reset();
    }
  }

  public function delete()
  {
    $realpath = $this->fileSystem->realpath($this->path());
    if (file_exists($realpath)) {
      $this->fileSystem->deleteRecursive($this->path());
      unset(self::$STATES[$this->site->id()]);
    }
  }

  /**
   * Enregistre un ancien domaine dans l'historique de ce site.
   * Appelé avant chaque changement de domaine pour conserver la trace
   * nécessaire aux redirections 301 automatiques.
   */
  public function recordDomainChange(string $oldDomain): void
  {
    if (!in_array($oldDomain, $this->previousDomains, true)) {
      $this->previousDomains[] = $oldDomain;
    }
  }
}
