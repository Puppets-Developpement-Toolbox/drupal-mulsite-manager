<?php

namespace Drupal\multisite_manager;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Service for managing site cloning queue operations.
 */
class SiteCloningQueueManager
{
  /**
   * The site cloning queue.
   */
  protected QueueInterface $queue;

  /**
   * The logger service.
   */
  protected LoggerChannelInterface $logger;

  /**
   * The file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The messenger service.
   */
  protected MessengerInterface $messenger;

  /**
   * Constructs a new SiteCloningQueueManager object.
   */
  public function __construct(
    QueueFactory $queue_factory,
    LoggerChannelInterface $logger,
    FileSystemInterface $file_system,
    MessengerInterface $messenger,
  ) {
    $this->queue = $queue_factory->get("site_cloning_queue");
    $this->logger = $logger;
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
  }

  /**
   * Queue all operations needed to clone a site.
   */
  public function queueSiteCloning(SiteInterface $site): void
  {
    $operations = self::getOperations();
    $total_steps = count($operations);

    $this->logger->info(
      "Queueing @count operations for site cloning: @domain",
      [
        "@count" => $total_steps,
        "@domain" => $site->domain(),
      ],
    );

    // Mark the site as being cloned
    $this->markCloningInProgress($site);

    $step = 0;
    $queue_item = [
      "site_id" => $site->id(),
      "step" => $step,
      "queued_at" => time(),
    ];

    $this->queue->createItem($queue_item);

    $this->messenger->addStatus(
      \Drupal::translation()->translate(
        "Site cloning has been queued for @domain. The process will complete in the background.",
        [
          "@domain" => $site->domain(),
        ],
      ),
    );

    $this->logger->info("Queued @count operations for site @domain", [
      "@count" => $total_steps,
      "@domain" => $site->domain(),
    ]);
  }

  /**
   * Get the list of operations needed for site cloning.
   */
  public static function getOperations(): array
  {
    $operations = [
      "create_site_directory",
      "create_database",
      "dump_blueprint_database",
      "import_database",
      "copy_private_folder",
      "copy_public_folder",
    ];

    // Add delete users operation if oauth2_server module exists
    if (\Drupal::moduleHandler()->moduleExists("oauth2_server")) {
      $operations[] = "delete_users";
    }

    // Always rebuild cache as the last step
    $operations[] = "rebuild_cache";

    return $operations;
  }

  /**
   * Mark the site as being in the cloning process.
   */
  protected function markCloningInProgress(SiteInterface $site): void
  {
    $currentState = SiteState::loadState($site, $this->fileSystem);
    $currentState->published = true;
    $currentState->cloned = false; // Will be set to true when cloning completes
    $currentState->write();
  }

  /**
   * Get the number of pending items in the queue.
   */
  public function getQueueSize(): int
  {
    return $this->queue->numberOfItems();
  }

  /**
   * Get pending operations for a specific site.
   */
  public function getPendingOperationsForSite(int $site_id): array
  {
    // Note: This is a simplified implementation.
    // For a production system, you might want to store queue items
    // in a separate table to allow for better querying.
    return [];
  }

  /**
   * Check if a site is currently being cloned.
   */
  public function isSiteBeingCloned(SiteInterface $site): bool
  {
    $currentState = SiteState::loadState($site, $this->fileSystem);
    return $currentState->published && !$currentState->cloned;
  }

  /**
   * Cancel pending operations for a site.
   */
  public function cancelSiteCloning(SiteInterface $site): void
  {
    // Note: Drupal's core queue API doesn't provide an easy way to remove
    // specific items from the queue. For a production system, you might
    // want to implement a custom queue that supports item removal.

    $this->logger->warning(
      "Cancellation of site cloning requested for @domain, but items may still be processed from queue",
      [
        "@domain" => $site->domain(),
      ],
    );

    // Mark the site as not published to prevent further processing
    $currentState = SiteState::loadState($site, $this->fileSystem);
    $currentState->published = false;
    $currentState->cloned = false;
    $currentState->write();
  }
}
