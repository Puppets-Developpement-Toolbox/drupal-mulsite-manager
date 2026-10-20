<?php

namespace Drupal\multisite_manager\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\multisite_manager\Entity\Site;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniqueDomainNameConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected string $appRoot,
  )
  {}


  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->getParameter('app.root'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    $entity = $field->getEntity();

    foreach ($field as $item) {
      if(!$this->isValidDomainName($item->value)) {
        $this->context->addViolation(
          $constraint->invalidDomaineName,
          ['%value' => $item->value]
        );
      }
      if (!$this->isUnique($item->value, $entity)) {
        $this->context->addViolation(
          $constraint->notUnique,
          ['%value' => $item->value]
        );
      }
      if (!$this->isNotReservedDomainName($item->value)) {
        $this->context->addViolation(
          $constraint->domainNameIsReserved,
          ['%value' => $item->value]
        );
      }
      if ($segment = $this->findReservedPathSegment($item->value)) {
        $this->context->addViolation(
          $constraint->pathIsReserved,
          ['%value' => $item->value, '%segment' => $segment]
        );
      }
    }
  }

  // see https://stackoverflow.com/questions/1755144/how-to-validate-domain-name-in-php
  private function isValidDomainName(string $value)
  {
      $parsed = parse_url("http://{$value}");
      // parse failed
      if (!$parsed) return false;
      // check domain name validity
      $domain = $parsed['host'];
      if (!(
        preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain) //valid chars check
        && preg_match("/^.{1,253}$/", $domain) //overall length check
        && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain)//length of each label
      )) return false;
      // check complete value
      $restructured = $domain;
      $path = trim($parsed['path'] ?? '', '/');
      if($path) {
        $path_parts = explode('/', $path);
        foreach ($path_parts as $part) {
          if (!preg_match("/^[a-z\d-]{1,60}$/", $part)) return false; //length of each folder
        }
      }
      if($path) $restructured .= "/{$path}";
      if($restructured !== $value) return false;
      return true;
  }


  // A domain may contain folders (ex: "example.com/fr"). Two domains compete
  // if they are equal or if one is nested in the other ("example.com" and
  // "example.com/fr", "example.com/fr" and "example.com/fr/be"): the most
  // specific site would shadow part of the other one.
  // "example.com/fr" and "example.com/france" don't compete.
  private function isUnique(string $value, Site $site) {
    $value = trim($value, "/ \n\r\t\v\x00");

    // the value itself and its parents: "a.com/fr/be" => "a.com", "a.com/fr", "a.com/fr/be"
    $parents = [];
    $path = '';
    foreach (explode('/', $value) as $segment) {
      $path = $path === '' ? $segment : "{$path}/{$segment}";
      $parents[] = $path;
    }

    $query = $this->entityTypeManager->getStorage('multisite_manager_site')
      ->getQuery()
      ->accessCheck(false);
    // same domain, a parent of it, or a child of it
    $competing = $query->orConditionGroup()
      ->condition('domain', $parents, 'IN')
      ->condition('domain', "{$value}/", 'STARTS_WITH');
    $query->condition($competing);
    if($site->id()) {
      $query->condition('id', $site->id(), '<>');
    }
    return $query->count()->execute() === 0;
  }

  private function isNotReservedDomainName(string $value) {
    return str_contains($value, $_ENV['BLUEPRINT_URI']) === false && str_contains($value, $_ENV['FACTORY_URI']) === false;
  }

  // A site served under a folder ("example.com/test") must not use:
  // - an entry of the Drupal root as first folder ("core", "sites"...): the web
  //   server would serve the real directory instead of the site;
  // - a language code ("en", "fr", "pt-br"...) as any folder: it reads as a
  //   language prefix, while the site has its own prefixes after its folder.
  private function findReservedPathSegment(string $value): ?string {
    $segments = array_slice(explode('/', trim($value, '/')), 1);
    if (!$segments) return null;

    if (in_array($segments[0], scandir($this->appRoot), true)) {
      return $segments[0];
    }
    $langcodes = array_keys(LanguageManager::getStandardLanguageList());
    foreach ($segments as $segment) {
      if (in_array($segment, $langcodes, true)) return $segment;
    }
    return null;
  }
}
