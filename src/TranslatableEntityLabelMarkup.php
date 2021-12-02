<?php

namespace Drupal\wienimal_editor_toolbar;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\ToStringTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides translatable markup based on entity labels.
 *
 * Use case is primarily for when derivative plugin configuration uses entity
 * labels. If the configuration value is set directly to the entity label, the
 * value will be cached as a string in the plugin definition, and appear only
 * in the language it was cached in.
 *
 * Taken from https://www.drupal.org/project/drupal/issues/3038717.
 * Use until this lands in core.
 */
class TranslatableEntityLabelMarkup implements MarkupInterface
{
    use ToStringTrait;

    /**
     * Entity type ID of entity whose label is to be displayed translated.
     *
     * @var string
     */
    protected $entityTypeId;

    /**
     * Entity ID of entity whose lable is to be displayed translated.
     *
     * @var string
     */
    protected $entityId;

    /**
     * The language the entity label should be translated to.
     *
     * @var string
     */
    protected $langcode;

    /**
     * Entity type manager.
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * Entity repository.
     *
     * @var EntityRepositoryInterface
     */
    protected $entityRepository;

    /**
     * The language manager.
     *
     * @var LanguageManagerInterface
     */
    protected $languageManager;

    /**
     * TranslatableEntityLabelMarkup constructor.
     *
     * @param string $entity_type_id
     *   Entity type ID of entity whose label is to be displayed translated.
     * @param string $entity_id
     *   Entity ID of entity whose lable is to be displayed translated.
     * @param string|null $langcode
     *   The language the entity label should be translated to, or the current
     *   interface language if NULL.
     * @param EntityTypeManagerInterface|null $entity_type_manager
     *   The entity type manager.
     * @param EntityRepositoryInterface|null $entity_repository
     *   The entity repository.
     * @param LanguageManagerInterface $language_manager
     *   The language manager.
     */
    public function __construct($entity_type_id, $entity_id, $langcode = null, ?EntityTypeManagerInterface $entity_type_manager = null, ?EntityRepositoryInterface $entity_repository = null, ?LanguageManagerInterface $language_manager = null)
    {
        $this->entityTypeId = $entity_type_id;
        $this->entityId = $entity_id;
        $this->entityTypeManager = $entity_type_manager;
        $this->entityRepository = $entity_repository;
        $this->languageManager = $language_manager;
        $this->langcode = $langcode ?: $this->getLanguageManager()->getCurrentLanguage()->getId();
    }

    /** Magic __sleep() method to avoid serializing services. */
    public function __sleep()
    {
        return ['entityTypeId', 'entityId'];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $this->__toString();
    }

    public function render()
    {
        try {
            // Set the configuration override language to the site language specified
            // by the class langcode property if:
            // 1. the site is multilingual
            // 2. the desired langcode is different from the current configuration
            // langcode
            // 3. the desired langcode exists as site language
            // This is done ahead of loading the entity from storage in case it is a
            // config entity, so the config will be loaded with language overrides.
            $language_manager = $this->getLanguageManager();
            if ($language_manager->isMultilingual() &&
                ($original_config_language = $language_manager->getConfigOverrideLanguage()) &&
                ($this->langcode !== $original_config_language->getId()) &&
                ($override_language = $language_manager->getLanguage($this->langcode))) {
                $language_manager->setConfigOverrideLanguage($override_language);
            }

            if (($entity = $this->getEntityTypeManager()->getStorage($this->entityTypeId)->load($this->entityId))) {
                // Load the entity translation and get the label.
                $string = $this->getTranslatedLabel($entity);
            } else {
                // Getting here means no exception thrown, but no matching entity found.
                $string = new TranslatableMarkup('Entity %entity_id of type %entity_type_id not found.', [
                    '%entity_id' => $this->entityId,
                    '%entity_type_id' => $this->entityTypeId,
                ], [
                    'langcode' => $this->langcode,
                ]);
            }
        } catch (PluginNotFoundException $e) {
            // Handle exception thrown when trying to get storage of non-existent
            // entity type.
            $string = new TranslatableMarkup('Entity type %entity_type_id does not exist.', [
                '%entity_type_id' => $this->entityTypeId,
            ], [
                'langcode' => $this->langcode,
            ]);
        } catch (InvalidPluginDefinitionException $e) {
            // Handle exception thrown when entity storage handler couldn't be loaded.
            $string = new TranslatableMarkup('Storage handler for %entity_type_id not found.', [
                '%entity_type_id' => $this->entityTypeId,
            ], [
                'langcode' => $this->langcode,
            ]);
        }

        // Set the config override language back to what it was, if it was changed.
        if (isset($override_language)) {
            $language_manager->setConfigOverrideLanguage($original_config_language);
        }

        if ($string instanceof TranslatableMarkup) {
            $string = (string) $string;
        }
        return $string;
    }

    public function count()
    {
        return mb_strlen($this->render());
    }

    /**
     * Gets the entity type manager.
     *
     * @return EntityTypeManagerInterface
     *   The entity type manager.
     */
    protected function getEntityTypeManager()
    {
        if (!$this->entityTypeManager) {
            $this->entityTypeManager = \Drupal::entityTypeManager();
        }

        return $this->entityTypeManager;
    }

    /**
     * Gets the entity repository.
     *
     * @return EntityRepositoryInterface
     *   The entity repository.
     */
    protected function getEntityRepository()
    {
        if (!$this->entityRepository) {
            $this->entityRepository = \Drupal::service('entity.repository');
        }

        return $this->entityRepository;
    }

    /**
     * Gets the language manager.
     *
     * @return LanguageManagerInterface
     *   The language manager.
     */
    protected function getLanguageManager()
    {
        if (!$this->languageManager) {
            $this->languageManager = \Drupal::service('language_manager');
        }

        return $this->languageManager;
    }

    /**
     * Gets the translated label from the entity.
     *
     * @param EntityInterface $entity
     *   The entity to get the translated label from.
     *
     * @return string
     *   The translated label.
     */
    protected function getTranslatedLabel(EntityInterface $entity)
    {
        return Html::escape($this->getEntityRepository()->getTranslationFromContext($entity, $this->langcode)->label());
    }
}
