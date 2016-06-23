<?php

/**
 * @file
 * Contains \Drupal\pubkey_encrypt\Plugin\LoginCredentialsProvider\UserPasswords.
 */

namespace Drupal\pubkey_encrypt\Plugin\LoginCredentialsProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\pubkey_encrypt\Plugin\LoginCredentialsProviderBase;

/**
 * A login credentials provider based on user passwords.
 *
 * @LoginCredentialsProvider(
 *   id = "user_passwords",
 *   name = @Translation("User Passwords"),
 *   description = @Translation("A login credentials provider based on users login passwords.")
 * )
 */
class UserPasswords extends LoginCredentialsProviderBase {

  public function fetchLoginCredentials($form, FormStateInterface &$form_state) {
    $password = $form_state->getValue('pass');
    return $password;
  }

}