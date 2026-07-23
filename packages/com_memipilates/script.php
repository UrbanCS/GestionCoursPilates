<?php
/**
 * Memi Pilates component installation guard.
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

return new class implements InstallerScriptInterface {
    private const MINIMUM_JOOMLA = '4.4.0';
    private const MINIMUM_PHP = '8.1.0';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP),
                'error'
            );

            return false;
        }

        if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        $this->removeLegacyConfigurationRules();

        return true;
    }

    /**
     * A component update does not automatically remove rules for actions that
     * disappeared from access.xml. Purge the legacy configuration grants so a
     * previously authorised user cannot reach the Square secrets via com_config.
     */
    private function removeLegacyConfigurationRules(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $assetName = 'com_memipilates';
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('rules')])
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = :asset_name')
            ->bind(':asset_name', $assetName);
        $db->setQuery($query);
        $asset = $db->loadObject();
        if ($asset === null) {
            return;
        }

        $rules = json_decode((string) $asset->rules, true);
        if (!is_array($rules)) {
            return;
        }

        $changed = false;
        foreach (['core.options', 'settings.manage', 'square.configure'] as $obsoleteAction) {
            if (array_key_exists($obsoleteAction, $rules)) {
                unset($rules[$obsoleteAction]);
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }

        $assetId = (int) $asset->id;
        $encodedRules = json_encode((object) $rules, JSON_UNESCAPED_SLASHES);
        if ($encodedRules === false) {
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules') . ' = :rules')
            ->where($db->quoteName('id') . ' = :asset_id')
            ->bind(':rules', $encodedRules)
            ->bind(':asset_id', $assetId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }
};
