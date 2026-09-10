<?php

namespace Drupal\multisite_manager\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueFactoryInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\multisite_manager\SiteCloningQueueManager;
use Drupal\multisite_manager\SiteInterface;
use Drupal\multisite_manager\SiteStorage;
use Drupal\multisite_manager\SiteState;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for processing site cloning operations.
 *
 * @QueueWorker(
 *   id = "site_cloning_queue",
 *   title = @Translation("Site Cloning Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class SiteCloningQueueWorker extends QueueWorkerBase implements
  ContainerFactoryPluginInterface
{
  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get("logger.channel.multisite_manager"),
      $container->get("file_system"),
      $container->get("entity_type.manager"),
      $container->get("queue")->get("site_cloning_queue"),
    );
  }

  /**
   * Constructs a new SiteCloningQueueWorker object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private LoggerChannelInterface $logger,
    private FileSystemInterface $fileSystem,
    private EntityTypeManagerInterface $entityTypeManager,
    private QueueInterface $queue,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data)
  {
    if (!isset($data["site_id"], $data["step"])) {
      $this->logger->error("Invalid queue item data: missing required fields");
      return;
    }

    $site_id = $data["site_id"];
    $step = $data["step"];
    $operations = SiteCloningQueueManager::getOperations();
    $operation = $operations[$data["step"]];
    $total_steps = count($operations);

    try {
      // Load the site entity
      $site_storage = $this->entityTypeManager->getStorage(
        "multisite_manager_site",
      );
      $site = $site_storage->load($site_id);

      if (!$site instanceof SiteInterface) {
        $this->logger->error("Site with ID @id not found", ["@id" => $site_id]);
        return;
      }

      $this->logger->info(
        "Processing site cloning step @step/@total for site @site (@operation)",
        [
          "@step" => $step + 1,
          "@total" => $total_steps,
          "@site" => $site->domain(),
          "@operation" => $operation,
        ],
      );

      // Initialize context array for operation callbacks
      $context = ["message" => "", "results" => []];

      // Execute the operation
      switch ($operation) {
        case "create_site_directory":
          SiteStorage::createSiteDirectoryOperation($site, $context);
          break;

        case "create_database":
          SiteStorage::createDatabaseOperation($site, $context);
          break;

        case "dump_blueprint_database":
          SiteStorage::dumpBlueprintDatabaseOperation($site, $context);
          break;

        case "import_database":
          SiteStorage::importDatabaseOperation($site, $context);
          break;

        case "copy_private_folder":
          SiteStorage::copyPrivateFolderOperation($site, $context);
          break;

        case "copy_public_folder":
          SiteStorage::copyPublicFolderOperation($site, $context);
          break;

        case "delete_users":
          SiteStorage::deleteUsersOperation($site, $context);
          break;

        case "rebuild_cache":
          SiteStorage::rebuildCacheOperation($site, $context);
          // Mark cloning as complete
          $this->markCloningComplete($site);
          break;

        default:
          throw new \InvalidArgumentException(
            "Unknown operation: {$operation}",
          );
      }

      $this->logger->info(
        "Completed site cloning step @step/@total for site @site (@operation)",
        [
          "@step" => $step + 1,
          "@total" => $total_steps,
          "@site" => $site->domain(),
          "@operation" => $operation,
        ],
      );

      // schedule next step
      $next_step = $step + 1;
      if (isset($operations[$next_step])) {
        $next_process = $data;
        $next_process["step"] = $next_step;
        $next_process["queued_at"] = time();
        $this->queue->createItem($next_process);
      }
    } catch (\Exception $e) {
      $this->logger->error(
        "Error processing site cloning queue item: @message",
        [
          "@message" => $e->getMessage(),
        ],
      );

      // Mark the site cloning as failed
      if (isset($site)) {
        $this->markCloningFailed($site, $e->getMessage());
      }

      throw $e;
    }
  }

  /**
   * Mark the site cloning as complete.
   */
  protected function markCloningComplete(SiteInterface $site): void
  {
    $currentState = SiteState::loadState($site, $this->fileSystem);
    $currentState->cloned = true;
    $currentState->write();

    $this->logger->info("Site cloning completed successfully for @domain", [
      "@domain" => $site->domain(),
    ]);

    // Send success message to user (you might want to implement a notification system)
    \Drupal::messenger()->addStatus(
      \Drupal::translation()->translate(
        "Site creation completed successfully for @domain",
        [
          "@domain" => $site->domain(),
        ],
      ),
    );
  }

  /**
   * Mark the site cloning as failed.
   */
  protected function markCloningFailed(
    SiteInterface $site,
    string $error_message,
  ): void {
    $currentState = SiteState::loadState($site, $this->fileSystem);
    $currentState->published = false; // Unpublish failed sites
    $currentState->cloned = false;
    $currentState->error_message = $error_message;
    $currentState->write();

    $this->logger->error("Site cloning failed for @domain: @error", [
      "@domain" => $site->domain(),
      "@error" => $error_message,
    ]);

    // Send error message to user
    \Drupal::messenger()->addError(
      \Drupal::translation()->translate(
        "Site creation failed for @domain: @error",
        [
          "@domain" => $site->domain(),
          "@error" => $error_message,
        ],
      ),
    );
  }
}
