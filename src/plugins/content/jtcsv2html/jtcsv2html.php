<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Content.Jtcsv2html
 *
 * @author       Guido De Gobbis <support@joomtools.de>
 * @copyright    2021 JoomTools.de - All rights reserved.
 * @license      GNU General Public License version 3 or later
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Profiler\Profiler;

/**
 * Class plgContentJtcsv2html
 */
class plgContentJtcsv2html extends CMSPlugin
{
    const PLUGIN_REGEX = '@(<(\w+)[^>]*>)?{jtcsv2html (.*)}(</\\2>)?@';

    const PLUGIN_REGEX_OLD = '@(<(\w+)[^>]*>)?{csv2html (.*)}(</\\2>)?@';

    protected $app;

    protected $autoloadLanguage = true;

    private $layoutPath = [];

    private $articleId;

    private static $csv = [];

    private static $cids = [];

    private $cssFiles = [];

    private $delimiter = null;

    private $enclosure = null;

    private $filter = null;

    private $html = '';

    public function __construct(&$subject, $params)
    {
        parent::__construct($subject, $params);

        $basefolder       = $this->params->get('basefolder', 'jtcsv2html');
        $basefolder       = $basefolder == '-1' ? '' : '/' . $basefolder;
        $template         = $this->app->getTemplate();
        $this->layoutPath = array(
            JPATH_SITE . '/images' . $basefolder,
            JPATH_SITE . '/templates/' . $template . '/html/plg_' . $this->_type . '_' . $this->_name,
            JPATH_SITE . '/plugins/' . $this->_type . '/' . $this->_name . '/tmpl',
        );

        if ($this->app->isClient('administrator')) {
            if ($this->params->get('clearDB', 0)) {
                $this->dbClearAll();
            }
        }
    }

    private function dbClearAll()
    {
        $db    = Factory::getDBO();
        $query = $db->getQuery(true);

        // Zuruecksetzen des Parameters
        $this->params->set('clearDB', 0);
        $params = $this->params->toString();

        $query->update($db->quoteName('#__extensions'));
        $query->set($db->quoteName('params') . '=' . $db->quote($params));
        $query->where(
            array(
                $db->quoteName('type') . '=' . $db->quote('plugin'),
                $db->quoteName('element') . '=' . $db->quote($this->_name),
                $db->quoteName('folder') . '=' . $db->quote($this->_type),
            )
        );

        $db->setQuery($query);
        $db->execute();

        // Loeschen aller Daten aus der Datenbank
        $db->truncateTable('#__jtcsv2html');
        $db->truncateTable('#__jtcsv2html_assoc');
        $db->execute();

        $db->setQuery(
            'OPTIMIZE TABLE '
            . $db->quoteName('#__jtcsv2html')
            . ', ' . $db->quoteName('#__jtcsv2html_assoc')
        );
        $db->execute();
    }

    /**
     * Set CSS and database associations
     *
     * @param   string   $context  The context of the content being passed to the plugin
     * @param   object  &$article  The article object
     * @param   object  &$params   The article params
     * @param   integer  $page     The 'page' number
     *
     * @return   void
     * @since    3.0.1
     */
    public function onContentAfterDisplay($context, &$article, &$params, $page = 0)
    {
        if (empty($article->text)) {
            if (empty($article->introtext)) {
                return null;
            }

            $text = $article->introtext . $article->fulltext;
        } else {
            $text = $article->text;
        }

        if (strpos($text, 'jtcsv2html') === false
            || $this->app->isClient('administrator')
        ) {
            return null;
        }

        if (count($this->cssFiles) > 0) {
            $this->loadCSS();
        }

        $this->onContentEvent($context, $article, array('onDisplay' => true));

        // Set profiler start time and memory usage and mark afterLoad in the profiler.
        JDEBUG ? Profiler::getInstance('Application')->mark('plgContentJtcsv2html') : null;

        return null;
    }

    private function loadCSS()
    {
        $cssFiles = $this->cssFiles;

        foreach ($cssFiles as $cssFile) {
            HTMLHelper::_('stylesheet', $cssFile, array('version' => 'auto'));
        }
    }

    private function onContentEvent($context, $article, $options = array())
    {
        if ($this->params->get('cache', 0) == 0) {
            return true;
        }

        $isNew     = isset($options['isNew']) ? $options['isNew'] : false;
        $onSave    = isset($options['onSave']) ? $options['onSave'] : false;
        $onDisplay = isset($options['onDisplay']) ? $options['onDisplay'] : false;
        $onDelete  = isset($options['onDelete']) ? $options['onDelete'] : false;

        if (!$onDisplay) {
            $text = $article->introtext . $article->fulltext;

            $this->getArticleId($article->id);

            $matches = $this->getMatches($text);

            if (empty($matches)) {
                return true;
            }
        }

        if ($isNew) {
            return $this->setDbCidAssociation();
        }

        if ($onDelete) {
            return $this->deleteDbCidAssociation();
        }

        if (empty(self::$cids)) {
            return true;
        }

        return $this->updateDbCidAssociation($onSave);
    }

    private function getArticleId($id = null)
    {
        if (!empty($id)) {
            $this->articleId = $id;
        }

        return $this->articleId;
    }

    /**
     * Wertet die Pluginaufrufe aus
     *
     * @param   string  $article  der Artikeltext
     *
     * @return   mixed  array/bool
     *
     * @since   3.0.1
     */
    private function getMatches($article)
    {
        $calls = [];

        if (preg_match_all(self::PLUGIN_REGEX, $article, $matches, PREG_SET_ORDER)) {
            if (($match = $this->setMatches($matches)) !== false) {
                $calls = array_merge_recursive($calls, $match);
            }
        }

        if (preg_match_all(self::PLUGIN_REGEX_OLD, $article, $matches, PREG_SET_ORDER)) {
            if (($match = $this->setMatches($matches)) !== false) {
                $calls = array_merge_recursive($calls, $match);
            }
        }

        // Eventuell umdrehen,
        return !empty($calls) ? $calls : false;
    }

    /**
     * @param   array   $matches
     * @param   string  $articleId
     *
     * @return   array
     *
     * @since   3.0.1
     */
    private function setMatches($matches)
    {
        $search    = array(',', ' ');
        $replace   = array('.', '');
        $return    = array();
        $articleId = $this->getArticleId();

        foreach ($matches as $match) {
            $filter          = $this->filter;
            $tplName         = 'default';
            $path            = trim(str_ireplace($search, $replace, strip_tags($match[3])));
            $callParams      = explode('.', strtolower($path));
            $file            = trim(array_shift($callParams), '/\\');
            $file            = explode('/', $file);
            $fileName        = array_pop($file);
            $relFilePath     = implode('/', $file);
            $csvFile         = $this->layoutPath[0];
            $countCallParams = count($callParams);

            if (!empty($relFilePath)) {
                $csvFile .= '/' . $relFilePath;
            }

            $csvFile  .= '/' . $fileName . '.csv';
            $fileTime = (file_exists($csvFile)) ? filemtime($csvFile) : -1;

            if ($countCallParams > 0) {
                if ($countCallParams == 2) {
                    $tplName = array_shift($callParams);
                }

                if (in_array($callParams[0], array('on', 'off'))) {
                    if ($callParams[0] != 'on') {
                        $filter = 'off';
                    } else {
                        $filter = 'on';
                    }
                } else {
                    $tplName = $callParams[0];
                }
            }

            $path = $fileName . '.' . $tplName;

            if (empty(self::$csv[$path])) {
                self::$csv[$path]['fileName']    = $fileName;
                self::$csv[$path]['relFilePath'] = $relFilePath;
                self::$csv[$path]['filePath']    = $csvFile;
                self::$csv[$path]['tplName']     = $tplName;
                self::$csv[$path]['fileTime']    = $fileTime;
            }

            if (empty(self::$cids[$articleId]) || !in_array($fileName, self::$cids[$articleId])) {
                self::$cids[$articleId][] = $fileName;
            }

            $return[$path][$filter][] = $match[0];
        }

        return $return;
    }

    private function setDbCidAssociation()
    {
        $db    = Factory::getDBO();
        $query = $db->getQuery(true);

        $query->insert($db->quoteName('#__jtcsv2html_assoc'));
        $query->columns($db->quoteName('cid') . ',' . $db->quoteName('filename'));

        foreach (self::$cids as $cid => $associations) {
            foreach ($associations as $filename) {
                $query->values($db->quote($cid) . ',' . $db->quote($filename));
            }
        }

        $query = str_replace('INSERT INTO', 'INSERT IGNORE INTO', (string) $query);
        $db->setQuery($query);

        return ($db->execute()) ? true : false;
    }

    /**
     * @return bool
     */
    private function deleteDbCidAssociation($from = null)
    {
        $db       = Factory::getDBO();
        $query    = $db->getQuery(true);
        $toDelete = [];

        $query->delete($db->quoteName('#__jtcsv2html_assoc'));

        foreach (self::$cids as $cid => $associations) {
            $query->where($db->quoteName('cid') . '=' . $db->quote($cid));

            foreach ($associations as $filename) {
                if ($from == 'update') {
                    $toDelete[] = $db->quoteName('filename') . '!=' . $db->quote($filename);
                } else {
                    $toDelete[] = $db->quoteName('filename') . '=' . $db->quote($filename);
                }
            }

            if ($from == 'update') {
                $query->extendWhere('AND', $toDelete);
            } else {
                $query->extendWhere('AND', $toDelete, 'OR');
            }
        }

        $db->setQuery($query);

        return ($db->execute()) ? true : false;
    }

    private function updateDbCidAssociation($onSave = false)
    {
        if ($onSave) {
            return $this->deleteDbCidAssociation('update');
        }

        return $this->setDbCidAssociation();
    }

    /**
     * Plugin that loads formatted csv-files within content
     *
     * @param   string   $context  The context of the content being passed to the plugin.
     * @param   object   $article  The article object.  Note $article->text is also available
     * @param   mixed    $params   The article params
     * @param   integer  $page     The 'page' number
     *
     * @return  mixed   true if there is an error. Void otherwise.
     *
     * @since   1.6
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        if (empty($article->text)
            || (strpos($article->text, '{jtcsv2html') === false
                && strpos($article->text, '{csv2html') === false)
            || ($context == 'com_content.category' && $this->app->input->get('layout', null) != 'blog')
            || $this->app->isClient('administrator')
        ) {
            return true;
        }

        $articleId = !empty($article->id) ? $article->id : null;
        $this->getArticleId($articleId);

        $this->delimiter = trim($this->params->get('delimiter', ','));
        $this->enclosure = trim($this->params->get('enclosure', '"'));
        $this->filter    = ($this->params->get('filter', 0) == 1) ? 'on' : 'off';
        $cache           = $this->params->get('cache', 1);

        /* Pluginaufruf auslesen */
        if (($matches = $this->getMatches($article->text)) === false) {
            return true;
        }

        foreach ($matches as $path => $calls) {
            if (self::$csv[$path]['fileTime'] !== -1) {
                if ($cache) {
                    if (empty(self::$csv[$path]['cache'])) {
                        $setOutput = $this->getDbCache($path);
                    } else {
                        $this->html = self::$csv[$path]['cache'];
                        $setOutput  = true;
                        unset(self::$cids[$path]);
                    }
                } else {
                    $setOutput = $this->readCsv($path);

                    if ($setOutput) {
                        $setOutput = $this->buildHtml($path);
                    }
                }

                /* Plugin-Aufruf durch HTML-Ausgabe ersetzen */
                if ($setOutput) {
                    foreach ($calls as $filter => $call) {
                        $output = '<div class="jtcsv2html_wrapper">';

                        if ($filter == 'on') {
                            $output .= '<div class="jtcsv2html_filter_wrapper">';
                            $output .= '<label for="jtcsv2htmlFilter">';
                            $output .= Text::_('PLG_CONTENT_JTCSV2HTML_FILTER_LABEL');
                            $output .= '</label>';
                            $output .= '<input id="jtcsv2htmlFilter" type="text" placeholder="'
                                . Text::_('PLG_CONTENT_JTCSV2HTML_FILTER_PLACEHOLDER') . '">';
                            $output .= '</div>';

                            HTMLHelper::_(
                                'script',
                                'plg_content_jtcsv2html/jtcsv2htmlFilter.js',
                                array('version' => 'auto')
                            );
                        }

                        $output .= $this->html;
                        $output .= '</div>';

                        if (!class_exists('Minify_HTML')) {
                            require_once 'assets/minifyHTML.inc';
                        }

                        $output        = Minify_HTML::minify($output);
                        $article->text = str_replace($call, $output, $article->text);

                        $this->setCss($path);
                    }
                } else {
                    $this->app->enqueueMessage(
                        JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', self::$csv[$path]['tplName'] . '.php'),
                        'warning'
                    );
                }
            } else {
                $this->app->enqueueMessage(
                    JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', self::$csv[$path]['fileName'] . '.csv'),
                    'warning'
                );

                unset(self::$csv[$path]);
            }

            unset($this->html);
        }
    }

    private function getDbCache($path)
    {
        $filetime = self::$csv[$path]['fileTime'];
        list($filename, $tplname) = explode('.', $path);
        $dbAction = null;
        $db       = Factory::getDBO();
        $query    = $db->getQuery(true);

        $query->select($db->quoteName(array('filetime', 'datas')));
        $query->from($db->quoteName('#__jtcsv2html'));
        $query->where($db->quoteName('filename') . '=' . $db->quote($filename));
        $query->where($db->quoteName('tpl') . '=' . $db->quote($tplname));

        $db->setQuery($query);

        $result     = $db->loadAssoc();
        $dbFiletime = !empty($result['filetime']) ? $result['filetime'] : null;

        if ($dbFiletime !== null) {
            $dbAction = (($filetime - $dbFiletime) <= 0) ? 'load' : 'update';
        }

        switch (true) {
            case ($dbAction == 'load'):
                $this->html = $result['datas'];

                return true;
                break;
            case ($dbAction == 'update'):
                return $this->dbUpdateCache($path);
                break;
            default:
                return $this->dbSaveCache($path);
                break;
        }
    }

    private function dbUpdateCache($path)
    {
        $filetime = self::$csv[$path]['fileTime'];
        list($filename, $tplname) = explode('.', $path);

        if ($this->readCsv($path) && $this->buildHtml($path)) {
            $db    = Factory::getDBO();
            $query = $db->getQuery(true);

            $query->update('#__jtcsv2html');
            $query->set(
                array(
                    $db->quoteName('filetime') . '=' . $db->quote($filetime),
                    $db->quoteName('datas') . '=' . $db->quote($this->html),
                )
            )
                ->where($db->quoteName('filename') . '=' . $db->quote($filename))
                ->where($db->quoteName('tpl') . '=' . $db->quote($tplname));

            $db->setQuery($query);

            return ($db->execute()) ? true : false;
        }

        return false;
    }

    private function readCsv($path)
    {
        $csvFile = self::$csv[$path]['filePath'];

        if ($this->delimiter == 'null') {
            $this->delimiter = ' ';
        }

        if ($this->delimiter == '\t') {
            $this->delimiter = "\t";
        }

        if (file_exists($csvFile) || is_readable($csvFile)) {
            $csvArray = @file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($csvArray as &$row) {
                $row = str_getcsv($row, $this->delimiter, "$this->enclosure");

                array_walk(
                    $row,
                    function (&$entry) {
                        $enc   = mb_detect_encoding($entry, "UTF-8,ISO-8859-1,WINDOWS-1252,CP1252");
                        $entry = ($enc == 'UTF-8') ? trim($entry) : trim(iconv($enc, 'UTF-8', $entry));
                    }
                );
            } //endforeach

            self::$csv[$path]['content'] = $csvArray;

            return true;
        }

        return false;
    }

    private function buildHtml($path)
    {
        $fileName = self::$csv[$path]['fileName'];
        $tplName  = self::$csv[$path]['tplName'];
        $options  = array('csv' => self::$csv[$path]);
        $layout   = new FileLayout($fileName);

        $layout->setIncludePaths($this->layoutPath);
        $layout->setDebug(JDEBUG);

        if (!empty($this->html = $layout->render($options))) {
            return true;
        } else {
            if ($this->html = $layout->setLayoutId($tplName)->render($options)) {
                return true;
            }
        }

        return false;
    }

    private function dbSaveCache($path)
    {
        $filetime = self::$csv[$path]['fileTime'];
        list($filename, $tplname) = explode('.', $path);

        if ($this->readCsv($path) && $this->buildHtml($path)) {
            $db    = Factory::getDBO();
            $query = $db->getQuery(true);

            $query->insert($db->quoteName('#__jtcsv2html'))
                ->set(
                    array(
                        $db->quoteName('filename') . '=' . $db->quote($filename),
                        $db->quoteName('tpl') . '=' . $db->quote($tplname),
                        $db->quoteName('filetime') . '=' . $db->quote($filetime),
                        $db->quoteName('datas') . '=' . $db->quote($this->html),
                    )
                );
            $db->setQuery($query);

            return ($db->execute()) ? true : false;
        }

        return false;
    }

    /**
     * @param $path
     */
    private function setCss($path)
    {
        $fileName    = self::$csv[$path]['fileName'] . '.css';
        $tplName     = self::$csv[$path]['tplName'] . '.css';
        $relFilePath = self::$csv[$path]['relFilePath'];
        $realpath    = realpath(JPATH_SITE);
        $cssPaths    = str_replace($realpath . '/', '', $this->layoutPath);

        if (!empty($relFilePath)) {
            $cssPaths[0] .= '/' . $relFilePath;
        }

        foreach ($this->layoutPath as $key => $layout) {
            if (!empty($relFilePath) && $key == 0) {
                $layout .= '/' . $relFilePath;
            }

            if (file_exists($layout . '/' . $fileName)) {
                if (!in_array($cssPaths[$key] . '/' . $fileName, $this->cssFiles)) {
                    $this->cssFiles[] = $cssPaths[$key] . '/' . $fileName;
                }

                return true;
            }

            if (file_exists($layout . '/' . $tplName)) {
                if (!in_array($cssPaths[$key] . '/' . $tplName, $this->cssFiles)) {
                    $this->cssFiles[] = $cssPaths[$key] . '/' . $tplName;
                }

                return true;
            }
        }

        return false;
    }

    public function onContentAfterSave($context, $article, $isNew)
    {
        $this->onContentEvent($context, $article, array('onSave' => true, 'isNew' => $isNew));
    }

    public function onContentBeforeDelete($context, $data)
    {
        $this->onContentEvent($context, $data, array('onDelete' => true));
    }
}
