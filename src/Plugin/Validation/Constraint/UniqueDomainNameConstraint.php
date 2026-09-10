<?php

namespace Drupal\multisite_manager\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that the submitted value is a unique integer.
 *
 * @Constraint(
 *   id = "UniqueDomainName",
 *   label = @Translation("Unique Domain Name", context = "Validation"),
 *   type = "string"
 * )
 */
class UniqueDomainNameConstraint extends Constraint {

  public $invalidDomaineName = '%value is not a valid domain name';

  public $notUnique = '%value is not unique';

  public $domainNameIsReserved = '%value is a reserved domain name';

}
