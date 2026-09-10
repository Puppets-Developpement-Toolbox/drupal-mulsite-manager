<?php

namespace Drupal\multisite_manager\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\multisite_manager\Entity\Site;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniqueDomainNameConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager)
  {}


  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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
    }
  }

  // see https://stackoverflow.com/questions/1755144/how-to-validate-domain-name-in-php
  private function isValidDomainName(string $value)
  {
      return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $value) //valid chars check
              && preg_match("/^.{1,253}$/", $value) //overall length check
              && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $value)   ); //length of each label
  }


  private function isUnique(string $value, Site $site) {
    $query = $this->entityTypeManager->getStorage('multisite_manager_site')
      ->getQuery()
      ->accessCheck(false);
    $query->condition('domain', $value);
    if($site->id()) {
      $query->condition('id', $site->id(), '<>');
    }
    return $query->count()->execute() === 0;
  }

  private function isNotReservedDomainName(string $value) {
    return $value!==$_ENV['BLUEPRINT_URI'] && $value!==$_ENV['FACTORY_URI'];
  }
}
