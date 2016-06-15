<?php

/**
 * @file
 * Contains \Drupal\pubkey_encrypt\Plugin\KeyProvider\PubkeyEncryptKeyProvider.
 */

namespace Drupal\pubkey_encrypt\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\key\Plugin\KeyProviderBase;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderSettableValueInterface;
use Drupal\key\Exception\KeyValueNotSetException;
use Drupal\key\KeyInterface;
use Drupal\user\Entity\Role;
use Drupal\Core\Session\AccountInterface;

/**
 * Adds a key provider as per the requirements of Pubkey Encrypt module.
 *
 * @KeyProvider(
 *   id = "pubkey_encrypt",
 *   label = @Translation("Pubkey Encrypt"),
 *   description = @Translation("Stores and Retrieves the key as per the requirements of Pubkey Encrypt module."),
 *   storage_method = "pubkey_encrypt",
 *   key_value = {
 *     "accepted" = TRUE,
 *     "required" = FALSE
 *   }
 * )
 */
class PubkeyEncryptKeyProvider extends KeyProviderBase implements KeyPluginFormInterface, KeyProviderSettableValueInterface {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $roleOptions = [];
    foreach (Role::loadMultiple() as $role) {
      $roleOptions[$role->id()] = $role->label();
    }
    unset($roleOptions[AccountInterface::ANONYMOUS_ROLE]);
    unset($roleOptions[AccountInterface::AUTHENTICATED_ROLE]);

    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#description' => $this->t('Share keys would be generated and stored for all the users in this Role.'),
      '#options' => $roleOptions,
      '#default_value' => $this->getConfiguration()['role'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $key_value = '';

    $currentUserId = \Drupal::currentUser()->id();

    // Retrieve the actual key value from the Share key of user.
    $shareKeys = $this->configuration['share_keys'];
    if (isset($shareKeys[$currentUserId])) {
      $shareKey = base64_decode($shareKeys[$currentUserId]);

      // The Private key of the user should be here, if the user is logged in.
      $tempstore = \Drupal::service('user.private_tempstore')
        ->get('pubkey_encrypt');
      $privateKey = $tempstore->get('private_key');

      openssl_private_decrypt($shareKey, $decrypted, $privateKey);
      $key_value = $decrypted;
    }

    return $key_value;
  }

  /**
   * {@inheritdoc}
   */
  public function setKeyValue(KeyInterface $key, $key_value) {
    // Generate Share keys for all users from the specified role.
    $role = $this->configuration['role'];
    $shareKeys = [];
    $users = \Drupal::service('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['roles' => $role]);
    // Allow root user control over all keys irrespective of his role.
    // @todiscuss WE COULD GIVE THIS PREVILIGE TO ALL USERS WITH "ADMINISTER_KEYS" PERMISSION.
    if (!isset($users[1])) {
      $users[1] = \Drupal::service('entity_type.manager')
        ->getStorage('user')
        ->load('1');
    }
    // Each user will have a Share key.
    foreach ($users as $user) {
      $userId = $user->get('uid')->getString();
      $publicKey = $user->get('field_public_key')->getString();
      openssl_public_encrypt($key_value, $shareKey, $publicKey);
      $shareKeys[$userId] = base64_encode($shareKey);
    }

    // Store the Share keys.
    if ($this->configuration['share_keys'] = $shareKeys) {
      return TRUE;
    }
    else {
      throw new KeyValueNotSetException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteKeyValue(KeyInterface $key) {
    // Nothing needs to be done, since the value will have been deleted
    // with the Key entity.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function obscureKeyValue($key_value, array $options = []) {
    // Key values are not obscured when this provider is used.
    return $key_value;
  }

}