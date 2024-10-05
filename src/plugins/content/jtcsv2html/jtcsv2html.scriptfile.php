<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Content.Jtcsv2html
 *
 * @author       Guido De Gobbis <support@joomtools.de>
 * @copyright    2021 JoomTools.de - All rights reserved.
 * @license      GNU General Public License version 3 or later
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Factory;
use Joomla\Registry\Registry;

/**
 * Script file of Joomla CMS
 *
 * @since  3.99.0
 */
class plgContentJtCsv2htmlInstallerScript
{
    /**
     * Minimum Joomla version to install
     *
     * @var    string
     *
     * @since  3.99.0
     */
    private $minimumJoomla = '3.10';

    /**
     * Minimum PHP version to install
     *
     * @var    string
     *
     * @since  3.99.0
     */
    private $minimumPhp = '7.4';

    /**
     * Installed version
     *
     * @var    string|null
     *
     * @since  3.99.0
     */
    private $installedVersion;

    /**
     * Current extension id on update.
     *
     * @var    int|null
     *
     * @since  3.99.0
     */
    private $extensionId;

    /**
     * Function to act prior the installation process begins
     *
     * @param   string     $action     Which action is happening (install|uninstall|discover_install|update)
     * @param   Installer  $installer  The class calling this method
     *
     * @return  boolean  True on success
     *
     * @since   3.99.0
     */
    public function preflight($action, $installer)
    {
        $app        = Factory::getApplication();

        Factory::getLanguage()->load('plg_content_jtcsv2html', dirname(__FILE__));

        if (version_compare(JVERSION, $this->minimumJoomla, 'lt')) {
            $app->enqueueMessage(Text::sprintf('PLG_CONTENT_JTCSV2HTML_MINJVERSION', $this->minimumJoomla), 'error');

            return false;
        }

        if (version_compare(PHP_VERSION, $this->minimumPhp, 'lt')) {
            $app->enqueueMessage(Text::sprintf('PLG_CONTENT_JTCSV2HTML_MINPHPVERSION', $this->minimumPhp), 'error');

            return false;
        }

        if (!is_dir(JPATH_ROOT . '/images/jtcsv2html')) {
            // create a folder inside your images folder
            Folder::create(JPATH_ROOT . '/images/jtcsv2html');
        }

        if ($action === 'update') {
            if (version_compare(JVERSION, '4', 'lt')) {
                $extensionId = $installer->get('currentExtensionId');
            } else {
                $extensionId = $installer->currentExtensionId;
            }

            if (!empty($extensionId)) {
                $this->extensionId = (int) $extensionId;

                // Set the version we are updating from
                $this->setInstalledVersion();

                // Remove old updateserver
                $this->removeOldUpdateserver();
            }
        }

        return true;
    }

    /**
     * Called after any type of action
     *
     * @param   string     $action     Which action is happening (install|uninstall|discover_install|update)
     * @param   Installer  $installer  The class calling this method
     *
     * @return  void
     *
     * @since   3.99.0
     */
    public function postflight($action, $installer)
    {
        if ($action === 'update') {
            if (version_compare($this->installedVersion, '3.99.0', 'lt')) {
                $pluginPath      = JPATH_PLUGINS . '/content/jtcsv2html';
                $deletes         = array();
                $deletes['file'] = array(
                    // Before 3.99.0
                    $pluginPath . '/assets/plg_jtcsv2html_search.js',
                    $pluginPath . '/assets/plg_jtcsv2html_search.min.js',
                    JPATH_ROOT . '/administrator/language/de-DE/de-DE.plg_content_jtcsv2html.ini',
                    JPATH_ROOT . '/administrator/language/de-DE/de-DE.plg_content_jtcsv2html.sys.ini',
                    JPATH_ROOT . '/administrator/language/en-GB/en-GB.plg_content_jtcsv2html.ini',
                    JPATH_ROOT . '/administrator/language/en-GB/en-GB.plg_content_jtcsv2html.sys.ini',
                );

                foreach ($deletes as $key => $orphans) {
                    $this->deleteOrphans($key, $orphans);
                }

                sleep(1);
            }
        }
    }

    /**
     * Remove old update servers by extension id.
     *
     * @return  void
     *
     * @since   3.99.0
     */
    private function removeOldUpdateserver() {
        $eid = $this->extensionId;
        // If we have a valid extension ID and the extension was successfully uninstalled wipe out any
        // update sites for it
        if ($eid)
        {
            $db = JFactory::getDbo();
            $query = $db->getQuery(true)
                ->delete('#__update_sites_extensions')
                ->where('extension_id = ' . $eid);
            $db->setQuery($query);
            $db->execute();

            // Delete any unused update sites
            $query->clear()
                ->select('update_site_id')
                ->from('#__update_sites_extensions');
            $db->setQuery($query);
            $results = $db->loadColumn();

            if (is_array($results))
            {
                // So we need to delete the update sites and their associated updates
                $updatesite_delete = $db->getQuery(true);
                $updatesite_delete->delete('#__update_sites');
                $updatesite_query = $db->getQuery(true);
                $updatesite_query->select('update_site_id')
                    ->from('#__update_sites');

                // If we get results back then we can exclude them
                if (count($results))
                {
                    $updatesite_query->where('update_site_id NOT IN (' . implode(',', $results) . ')');
                    $updatesite_delete->where('update_site_id NOT IN (' . implode(',', $results) . ')');
                }

                // So let's find what update sites we're about to nuke and remove their associated extensions
                $db->setQuery($updatesite_query);
                $update_sites_pending_delete = $db->loadColumn();

                if (is_array($update_sites_pending_delete) && count($update_sites_pending_delete))
                {
                    // Nuke any pending updates with this site before we delete it
                    // TODO: investigate alternative of using a query after the delete below with a query and not in like above
                    $query->clear()
                        ->delete('#__updates')
                        ->where('update_site_id IN (' . implode(',', $update_sites_pending_delete) . ')');
                    $db->setQuery($query);
                    $db->execute();
                }

                // Note: this might wipe out the entire table if there are no extensions linked
                $db->setQuery($updatesite_delete);
                $db->execute();
            }

            // Last but not least we wipe out any pending updates for the extension
            $query->clear()
                ->delete('#__updates')
                ->where('extension_id = ' . $eid);
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Set the plugin version from database
     *
     * @return  void
     *
     * @since   3.99.0
     */
    private function setInstalledVersion()
    {
        $db     = Factory::getDbo();
        $where  = array(
            $db->quoteName('extension_id') . ' = ' . $db->quote((int) $this->extensionId),
        );
        $select = array(
            $db->quoteName('manifest_cache'),
        );

        try {
            $result = $db->setQuery(
                $db->getQuery(true)
                    ->select($select)
                    ->from($db->quoteName('#__extensions'))
                    ->where($where)
            )->loadObject();
        } catch (Exception $e) {
            return;
        }

        $manifestCache          = new Registry($result->manifest_cache);
        $this->installedVersion = $manifestCache->get('version');
    }

    /**
     * Delete files and folders
     *
     * @param   string  $type     Which type are orphans of (file or folder)
     * @param   array   $orphans  Array of files or folders to delete
     *
     * @return  void
     *
     * @since   3.99.0
     */
    private function deleteOrphans($type, array $orphans)
    {
        $app = Factory::getApplication();

        foreach ($orphans as $item) {
            if ($type == 'folder' && (is_dir($item) && Folder::delete($item) === false)) {
                $app->enqueueMessage(Text::sprintf('PLG_CONTENT_JTCSV2HTML_NOT_DELETED', $item), 'warning');
            }

            if ($type == 'file' && (is_file($item) && File::delete($item) === false)) {
                $app->enqueueMessage(Text::sprintf('PLG_CONTENT_JTCSV2HTML_NOT_DELETED', $item), 'warning');
            }
        }
    }
}
