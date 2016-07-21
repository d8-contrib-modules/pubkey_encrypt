<?php

/**
 * @file
 * Contains \Drupal\pubkey_encrypt\PubkeyEncryptManager.
 */

namespace Drupal\pubkey_encrypt;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\pubkey_encrypt\Plugin\AsymmetricKeysManager;
use Drupal\pubkey_encrypt\Plugin\LoginCredentialsManager;
use Drupal\user\PrivateTempStoreFactory;
use Drupal\user\UserInterface;
use Drupal\user\Entity\Role;
use Drupal\Core\Session\AccountInterface;

/**
 * Handles users' Public/Private key pairs.
 */
class PubkeyEncryptManager {
  protected $entityTypeManager;
  protected $tempStore;

  /**
   * Status of the Pubkey Encrypt module i.e. initialized or not.
   *
   * @var bool
   */
  protected $moduleInitialized;

  /**
   * The plugin manager for asymmetric keys.
   *
   * @var \Drupal\pubkey_encrypt\Plugin\AsymmetricKeysManager
   */
  protected $asymmetricKeysManager;

  /**
   * The plugin manager for login credentials.
   *
   * @var \Drupal\pubkey_encrypt\Plugin\LoginCredentialsManager
   */
  protected $loginCredentialsManager;

  /**
   * Reference to an Asymmetric Keys Generator plugin.
   *
   * @var string
   */
  protected $asymmetricKeysGenerator;

  /**
   * Reference to a Login Credentials Provider plugin.
   *
   * @var string
   */
  protected $loginCredentialsProvider;

  /**
   * Configuration for the selected Asymmetric Keys Generator plugin.
   *
   * @var string[]
   */
  protected $asymmetricKeysGeneratorConfiguration;

  /**
   * Constructor with dependencies injected to it.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, PrivateTempStoreFactory $tempStoreFactory, AsymmetricKeysManager $asymmetric_keys_manager, LoginCredentialsManager $login_credentials_manager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->tempStore = $tempStoreFactory->get('pubkey_encrypt');
    $this->asymmetricKeysManager = $asymmetric_keys_manager;
    $this->loginCredentialsManager = $login_credentials_manager;

    // Pull module initialization settings from configuration.
    $config = \Drupal::config('pubkey_encrypt.initialization_settings');
    $this->moduleInitialized = $config->get('module_initialized');
    $this->asymmetricKeysGenerator = $config->get('asymmetric_keys_generator');
    $this->loginCredentialsProvider = $config->get('login_credentials_provider');
    $this->asymmetricKeysGeneratorConfiguration = $config->get('asymmetric_keys_generator_configuration');
  }

  /**
   * Initialize a specific user's keys.
   */
  public function initializeUserKeys(UserInterface $user) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    // Delegate the task of generating asymmetric keys to perspective plugin.
    $asymmetric_keys_generator = $this
      ->asymmetricKeysManager
      ->createInstance($this->asymmetricKeysGenerator, $this->asymmetricKeysGeneratorConfiguration);
    $keys = $asymmetric_keys_generator->generateAsymmetricKeys();

    // Set Public/Private keys.
    $user
      ->set('field_public_key', $keys['public_key'])
      ->set('field_private_key', $keys['private_key'])
      ->set('field_private_key_protected', 0)
      ->save();
  }

  /**
   * Protect a user keys with his credentials.
   */
  public function protectUserKeys(UserInterface $user, $credentials) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    // Get stored keys status.
    $isProtected = $user->get('field_private_key_protected')->getString();

    // Ensure that the keys have not already been protected.
    if (!$isProtected) {
      // Get original private key.
      $privateKey = $user->get('field_private_key')->getString();

      // Protect the original private key.
      // Since we're encrypting keys which are themselves pretty random, we
      // don't really need the IV to be random here too. Hence using all zeros.
      $protectedPrivateKey = openssl_encrypt($privateKey, 'AES-128-CBC', $credentials, 0, '0000000000000000');

      // Set new values for the fields.
      $user
        ->set('field_private_key', $protectedPrivateKey)
        ->set('field_private_key_protected', 1)
        ->save();
    }
  }

  /**
   * Fetch the private key of a user in its original form.
   */
  public function getOriginalPrivateKey(UserInterface $user, $credentials) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return NULL;
    }

    // Get stored private key.
    $privateKey = $user->get('field_private_key')->getString();

    // Get stored keys status.
    $isProtected = $user->get('field_private_key_protected')->getString();

    if ($isProtected) {
      // Decrypt protected private key using user credentials and return.
      $originalPrivateKey  = openssl_decrypt($privateKey, 'AES-128-CBC', $credentials, 0, '0000000000000000');
      return $originalPrivateKey;
    }
    else {
      return $privateKey;
    }
  }

  /**
   * Handle a change in user credentials.
   */
  public function userCredentialsChanged($userId, $currentCredentials, $newCredentials) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($userId);

    // Grab the original private key.
    $originalPrivateKey = $this->getOriginalPrivateKey($user, $currentCredentials);

    // Store it in original form.
    $user
      ->set('field_private_key', $originalPrivateKey)
      ->set('field_private_key_protected', 0)
      ->save();

    // Protect with new credentials.
    $this->protectUserKeys($user, $newCredentials);
  }

  /**
   * Fetch and temporarily store user's private key upon login.
   */
  public function userLoggedIn(UserInterface $user, $credentials) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $isProtected = $user->get('field_private_key_protected')->getString();

    // If it was the first-time login of a user, protect his keys first.
    if (!$isProtected) {
      $this->protectUserKeys($user, $credentials);
    }

    $originalPrivateKey = $this->getOriginalPrivateKey($user, $credentials);

    // Store private key in tempstore.
    $this->tempStore->set('private_key', $originalPrivateKey);
  }

  /**
   * Generate a Role key.
   */
  public function generateRoleKey(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $role_id = $role->id();
    $role_label = $role->label();

    // Generate a key; at this stage the key hasn't been configured completely.
    $values = [];
    $values["id"] = $role_id . "_role_key";
    $values["label"] = $role_label . " Role key";
    $values["description"] = $role_label . " Role key used by Pubkey Encrypt";
    $values["key_type"] = "encryption";
    $values["key_type_settings"]["key_size"] = "128";
    $values["key_input"] = "none";
    $values["key_provider"] = "pubkey_encrypt";
    $values["key_provider_settings"]["role"] = $role_id;
    \Drupal::entityTypeManager()
      ->getStorage('key')
      ->create($values)
      ->save();

    // Fetch the newly generated key from key repository.
    $new_key = \Drupal::service('key.repository')
      ->getKey($role_id . "_role_key");

    // Generate a value for the key.
    $new_key_value = $new_key
      ->getKeyType()
      ->generateKeyValue(array("key_size" => "128"));

    // Save the key with new value.
    // This would cause our Key Provider to save it as per the business logic.
    $new_key->setKeyValue($new_key_value);
    $new_key->save(\Drupal::entityTypeManager()->getStorage('key'));
  }

  /**
   * Update a Role key.
   */
  public function updateRoleKey(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    // Since only users with "administer permissions" permission have control
    // over all Role keys, so only they can trigger any Role key update.
    if (\Drupal::currentUser()->hasPermission("administer permissions")) {
      // Since we don't have a Role key for "authenticated" role.
      if ($role->id() != AccountInterface::AUTHENTICATED_ROLE) {
        // Fetch the Role key.
        $key = \Drupal::service('key.repository')
          ->getKey($role->id() . "_role_key");

        // Re-save the key with same value.
        // This would cause our Key Provider to cater for the update.
        $key->setKeyValue($key->getKeyValue());
        $key->save(\Drupal::entityTypeManager()->getStorage('key'));
      }
    }
  }

  /**
   * Update all Role keys.
   */
  public function updateAllRoleKeys() {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();

    // Since we don't have a Role key for these two roles.
    unset($roles[AccountInterface::ANONYMOUS_ROLE]);
    unset($roles[AccountInterface::AUTHENTICATED_ROLE]);

    foreach ($roles as $role) {
      $this->updateRoleKey($role);
    }
  }

  /**
   * Delete a Role key upon role removal.
   */
  public function deleteRoleKey(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    \Drupal::service('key.repository')
      ->getKey($role->id() . "_role_key")
      ->delete();
  }

  /**
   * Generate an Encryption profile for a Role key.
   */
  public function generateEncryptionProfile(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $values['id'] = $role->id() . '_role_key_encryption_profile';
    $values['label'] = $role->label() . ' Encryption Profile';
    $values['encryption_key'] = $role->id() . "_role_key";
    $values['encryption_method'] = 'test_encryption_method';

    $this->entityTypeManager
      ->getStorage('encryption_profile')
      ->create($values)
      ->save();
  }

  /**
   * Remove the Encryption Profile for a Role key.
   */
  public function removeEncryptionProfile(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return;
    }

    $this->entityTypeManager
      ->getStorage('encryption_profile')
      ->load($role->id() . '_role_key_encryption_profile')
      ->delete();
  }

  /**
   * Initialize the module.
   */
  public function initializeModule() {
    $this->refreshReferenceVariables();
    // Do initialization via Batch API.
    pubkey_encrypt_initialize_module();
  }

  /**
   * Fetch login credentials upon user login.
   */
  public function fetchLoginCredentials($form, $form_state) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return NULL;
    }

    // Delegate the task of fetching credentials to perspective plugin.
    $loginCredentialsProvider = $this
      ->loginCredentialsManager
      ->createInstance($this->loginCredentialsProvider);
    return $loginCredentialsProvider->fetchLoginCredentials($form, $form_state);
  }

  /**
   * Fetch changed login credentials upon user form edit.
   */
  public function fetchChangedLoginCredentials($form, $form_state) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return NULL;
    }

    // Delegate the task of fetching changed credentials to perspective plugin.
    $loginCredentialsProvider = $this
      ->loginCredentialsManager
      ->createInstance($this->loginCredentialsProvider);
    return $loginCredentialsProvider
      ->fetchChangedLoginCredentials($form, $form_state);
  }

  /**
   * Refresh reference variables.
   */
  public function refreshReferenceVariables() {
    // Pull latest module initialization settings from configuration.
    $config = \Drupal::config('pubkey_encrypt.initialization_settings');
    $this->moduleInitialized = $config->get('module_initialized');
    $this->asymmetricKeysGenerator = $config->get('asymmetric_keys_generator');
    $this->loginCredentialsProvider = $config->get('login_credentials_provider');
    $this->asymmetricKeysGeneratorConfiguration = $config->get('asymmetric_keys_generator_configuration');
  }

  /**
   * Add a role to the list of enabled roles in module settings.
   *
   * @param \Drupal\User\Entity\Role $role
   *   The role entity to be enabled.
   *
   * @return NULL|void
   *   Return NULL if the module Pubkey Encrypt has not been initialized yet.
   */
  public function enableRole(Role $role) {
    // Do nothing if the module hasn't been initialized yet.
    if ($this->moduleInitialized == FALSE) {
      return NULL;
    }

    $admin_settings = \Drupal::service('config.factory')
      ->getEditable('pubkey_encrypt.admin_settings');;

    $enabled_roles = $admin_settings->get('enabled_roles');
    $enabled_roles[$role->id()] = $role->id();

    $admin_settings->set('enabled_roles', $enabled_roles)->save();
  }

}
