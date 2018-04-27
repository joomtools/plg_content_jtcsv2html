<?php
/**
 * @Copyright  JoomTools
 * @package    JT - Csv2Html - Plugin for Joomla! 3.x
 * @author     Guido De Gobbis
 * @link       http://www.joomtools.de
 *
 * @license    GNU/GPL v3 <http://www.gnu.org/licenses/>
 *             This program is free software: you can redistribute it and/or modify
 *             it under the terms of the GNU General Public License as published by
 *             the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
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
	private $csv = [];
	private $cids = [];
	private $cssFiles = [];
	private $delimiter = null;
	private $enclosure = null;
	private $filter = null;
	private $_html = '';

	public function __construct(&$subject, $params)
	{
		parent::__construct($subject, $params);

		$template         = $this->app->getTemplate();
		$this->layoutPath = array(
			JPATH_SITE . '/images/jtcsv2html',
			JPATH_SITE . '/templates/' . $template . '/html/plg_' . $this->_type . '_' . $this->_name,
			JPATH_SITE . '/plugins/' . $this->_type . '/' . $this->_name . '/tmpl',
		);

		if ($this->app->isClient('administrator'))
		{
			if ($this->params->get('clearDB', 0))
			{
				$this->_dbClearAll();
			}
		}
	}

	private function _dbClearAll()
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

		$db->setQuery('OPTIMIZE TABLE ' . $db->quoteName('#__jtcsv2html') . ', ' . $db->quoteName('#__jtcsv2html_assoc'));
		$db->execute();
	}

	/**
	 * Set CSS and database associations
	 *
	 * @param   string  $context  The context of the content being passed to the plugin
	 * @param   object  &$article The article object
	 * @param   object  &$params  The article params
	 * @param   integer $page     The 'page' number
	 *
	 * @return  void
	 *
	 * @since   3.0.1
	 */
	public function onContentAfterDisplay($context, &$article, &$params, $page = 0)
	{
		$text = $article->introtext . $article->fulltext;

		if (strpos($text, 'jtcsv2html') !== false
			|| $context != 'com_finder.indexer'
			|| $this->app->isClient('site'))
		{

			if (count($this->cssFiles) > 0)
			{
				$this->_loadCSS();
			}

			$this->_onContentEvent($context, $article, array('onDisplay' => true));

			// Set profiler start time and memory usage and mark afterLoad in the profiler.
			JDEBUG ? Profiler::getInstance('Application')->mark('plgContentJtcsv2html') : null;
		}
	}

	private function _loadCSS()
	{
		$cssFiles = $this->cssFiles;

		foreach ($cssFiles as $cssFile)
		{
			JHtml::_('stylesheet', $cssFile, array('version' => 'auto'));
		}
	}

	private function _onContentEvent($context, $article, $options = array())
	{
		if ($this->params->get('cache', 0) == 0)
		{
			return true;
		}

		$isNew     = isset($options['isNew']) ? $options['isNew'] : false;
		$onSave    = isset($options['onSave']) ? $options['onSave'] : false;
		$onDisplay = isset($options['onDisplay']) ? $options['onDisplay'] : false;
		$onDelete  = isset($options['onDelete']) ? $options['onDelete'] : false;

		if (!$onDisplay)
		{
			$text = $article->introtext . $article->fulltext;

			$this->getArticleId($article->id);

			$matches = $this->getMatches($text);

			if (empty($matches))
			{
				return true;
			}
		}

		if ($isNew)
		{
			return $this->_setDbCidAssociation();
		}

		if ($onDelete)
		{
			return $this->_deleteDbCidAssociation();
		}

		return $this->_updateDbCidAssociation($onSave);
	}

	private function getArticleId($id = null)
	{
		if (!empty($id))
		{
			$this->articleId = $id;
		}

		return $this->articleId;
	}

	/**
	 * Wertet die Pluginaufrufe aus
	 *
	 * @param   string $article der Artikeltext
	 *
	 * @return   mixed  array/bool
	 *
	 * @since   3.0.1
	 */
	private function getMatches($article)
	{
		$calls = [];

		if (preg_match_all(self::PLUGIN_REGEX, $article, $matches, PREG_SET_ORDER))
		{
			if (($match = $this->setMatches($matches)) !== false)
			{
				$calls = array_merge_recursive($calls, $match);
			}
		}

		if (preg_match_all(self::PLUGIN_REGEX_OLD, $article, $matches, PREG_SET_ORDER))
		{
			if (($match = $this->setMatches($matches)) !== false)
			{
				$calls = array_merge_recursive($calls, $match);
			}
		}

		// Eventuell umdrehen,
		return !empty($calls) ? $calls : false;
	}

	/**
	 * @param   array  $matches
	 * @param   string $articleId
	 *
	 * @return   bool
	 *
	 * @since   3.0.1
	 */
	private function setMatches($matches)
	{
		$search    = array(',', '{csv2html', '{jtcsv2html', '}');
		$replace   = array('.', '');
		$return    = array();
		$articleId = $this->getArticleId();

		foreach ($matches as $match)
		{
			$filter          = $this->filter;
			$tplName         = 'default';
			$path            = trim(str_ireplace($search, $replace, strip_tags($match[0])));
			$callParams      = explode('.', strtolower($path));
			$fileName        = array_shift($callParams);
			$countCallParams = count($callParams);
			$csvFile         = JPATH_SITE . '/images/jtcsv2html/' . $fileName . '.csv';
			$filetime        = (file_exists($csvFile)) ? filemtime($csvFile) : -1;

			if ($countCallParams > 0)
			{
				if ($countCallParams == 2)
				{
					$tplName = array_shift($callParams);
				}

				if (in_array($callParams[0], array('on', 'off')))
				{
					if ($callParams[0] != 'on')
					{
						$filter = 'off';
					}
					else
					{
						$filter = 'on';
					}
				}
				else
				{
					$tplName = $callParams[0];
				}
			}

			$path = $fileName . '.' . $tplName;

			if (empty($this->csv[$path]))
			{
				$this->csv[$path]['fileName'] = $fileName;
				$this->csv[$path]['filePath'] = $csvFile;
				$this->csv[$path]['tplName']  = $tplName;
				$this->csv[$path]['filetime'] = $filetime;
			}

			if (empty($this->cids[$articleId]) || !in_array($path, $this->cids[$articleId]))
			{
				$this->cids[$articleId][] = $path;
			}

			$return[$path][$filter][] = $match[0];
		}

		return $return;
	}

	private function _setDbCidAssociation()
	{
		$db    = JFactory::getDBO();
		$query = $db->getQuery(true);

		$query->insert($db->quoteName('#__jtcsv2html_assoc'));
		$query->columns($db->quoteName('cid') . ',' . $db->quoteName('path'));

		foreach ($this->cids as $cid => $associations)
		{
			foreach ($associations as $path)
			{
				$query->values($db->quote($cid) . ',' . $db->quote($path));
			}
		}

		$query = str_replace('INSERT INTO', 'INSERT IGNORE INTO', (string) $query);
		$db->setQuery($query);

		return ($db->execute()) ? true : false;
	}

	/**
	 * @return bool
	 */
	private function _deleteDbCidAssociation($from = null)
	{
		$db       = JFactory::getDBO();
		$query    = $db->getQuery(true);
		$toDelete = [];

		$query->delete($db->quoteName('#__jtcsv2html_assoc'));

		foreach ($this->cids as $cid => $associations)
		{

			$query->where($db->quoteName('cid') . '=' . $db->quote($cid));

			foreach ($associations as $path)
			{
				if ($from == 'update')
				{
					$toDelete[] = $db->quoteName('path') . '!=' . $db->quote($path);
				}
				else
				{
					$toDelete[] = $db->quoteName('path') . '=' . $db->quote($path);
				}
			}

			if ($from == 'update')
			{
				$query->extendWhere('AND', $toDelete);
			}
			else
			{
				$query->extendWhere('AND', $toDelete, 'OR');
			}
		}

		$db->setQuery($query);

		return ($db->execute()) ? true : false;
	}

	private function _updateDbCidAssociation($onSave = false)
	{
		$execute = true;

		if ($onSave)
		{
			$execute = $this->_deleteDbCidAssociation('update');
		}

		if ($execute === false)
		{
			return false;
		}

		$execute = $this->_setDbCidAssociation();

		return $execute;
	}

	/**
	 * Plugin that loads formatted csv-files within content
	 *
	 * @param   string   $context   The context of the content being passed to the plugin.
	 * @param   object   &$article  The article object.  Note $article->text is also available
	 * @param   mixed    &$params   The article params
	 * @param   integer  $page      The 'page' number
	 *
	 * @return  mixed   true if there is an error. Void otherwise.
	 *
	 * @since   1.6
	 */
	public function onContentPrepare($context, &$article, &$params, $page = 0)
	{
		if (strpos($article->text, '{jtcsv2html') === false
			&& strpos($article->text, '{csv2html') === false
			|| $context == 'com_finder.indexer'
			|| $this->app->isClient('administrator')
		)
		{
			return true;
		}

		$articleId = !empty($article->id) ? $article->id : null;
		$this->getArticleId($articleId);

		$this->delimiter = trim($this->params->get('delimiter', ','));
		$this->enclosure = trim($this->params->get('enclosure', '"'));
		$this->filter    = $this->params->get('filter', 'off');
		$cache           = $this->params->get('cache', 1);

		/* Pluginaufruf auslesen */
		if (($matches = $this->getMatches($article->text)) === false)
		{
			return true;
		}

		foreach ($matches as $path => $calls)
		{
			if ($this->csv[$path]['filetime'] !== -1)
			{
				if ($cache)
				{
					if (empty($this->csv[$path]['cache']))
					{
						$setOutput = $this->getDbCache($path);
					}
					else
					{
						$this->_html = $this->csv[$path]['cache'];
						$setOutput   = true;
					}
				}
				else
				{
					$setOutput = $this->_readCsv($path);

					if ($setOutput)
					{
						$setOutput = $this->_buildHtml($path);
					}
				}

				/* Plugin-Aufruf durch HTML-Ausgabe ersetzen */
				if ($setOutput)
				{
					foreach ($calls as $filter => $call)
					{
						$output = '<div class="jtcsv2html_wrapper">';

						if ($filter == 'on')
						{
							$output .= '<input type="text" class="search" placeholder="'
								. Text::_('PLG_CONTENT_JTCSV2HTML_FILTER_PLACEHOLDER') . '">';
							JHtml::_('jquery.framework');
							JHtml::_('script', 'plugins/content/jtcsv2html/assets/plg_jtcsv2html_search.js', array('version' => 'auto'));
						}
						$output .= $this->_html;
						$output .= '</div>';

						if (!class_exists('Minify_HTML'))
						{
							require_once 'assets/minifyHTML.inc';
						}
						$output = Minify_HTML::minify($output);

						$article->text = str_replace($call, $output, $article->text);

						$this->setCss($path);
					}
				}
				else
				{
					$this->app->enqueueMessage(
						JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $this->csv[$path]['tplName'] . '.php')
						, 'warning'
					);

//					unset($this->csv[$path]);
				}
			}
			else
			{
				$this->app->enqueueMessage(
					JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $this->csv[$path]['fileName'] . '.csv')
					, 'warning'
				);

				unset($this->csv[$path]);
			}

			unset($this->_html);
		} //endforeach
	}

	private function getDbCache($path)
	{
		$filetime = $this->csv[$path]['filetime'];
		$dbAction = null;
		$db       = Factory::getDBO();
		$query    = $db->getQuery(true);

		$query->select($db->quoteName(array('filetime', 'datas')));
		$query->from($db->quoteName('#__jtcsv2html'));
		$query->where($db->quoteName('path') . '=' . $db->quote($path));

		$db->setQuery($query);

		$result     = $db->loadAssoc();
		$dbFiletime = !empty($result['filetime']) ? $result['filetime'] : null;

		if ($dbFiletime !== null)
		{
			$dbAction = (($filetime - $dbFiletime) <= 0) ? 'load' : 'update';
		}

		switch (true)
		{
			case ($dbAction == 'load'):
				$this->_html = $result['datas'];
				return true;
				break;
			case ($dbAction == 'update'):
				return $this->_dbUpdateCache($path);
				break;
			default:
				return $this->_dbSaveCache($path);
				break;
		}
	}

	private function _dbUpdateCache($path)
	{
		$filetime = $this->csv[$path]['filetime'];

		if ($this->_readCsv($path))
		{
			if ($this->_buildHtml($path))
			{
				$db    = JFactory::getDBO();
				$query = $db->getQuery(true);

				$query->update('#__jtcsv2html');
				$query->set(
					array(
						$db->quoteName('filetime') . '=' . $db->quote($filetime),
						$db->quoteName('datas') . '=' . $db->quote($this->_html),
					)
				)->where($db->quoteName('path') . '=' . $db->quote($path));

				$db->setQuery($query);

				return ($db->execute()) ? true : false;
			}
		}

		return false;
	}

	private function _readCsv($path)
	{
		$csvFile = $this->csv[$path]['filePath'];

		if ($this->delimiter == 'null')
		{
			$this->delimiter = ' ';
		}

		if ($this->delimiter == '\t')
		{
			$this->delimiter = "\t";
		}

		if (file_exists($csvFile) || is_readable($csvFile))
		{
			$csvArray = @file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

			foreach ($csvArray as &$row)
			{
				$row = str_getcsv($row, $this->delimiter, "$this->enclosure");

				array_walk($row,
					function (&$entry) {
						$enc   = mb_detect_encoding($entry, "UTF-8,ISO-8859-1,WINDOWS-1252");
						$entry = ($enc == 'UTF-8') ? trim($entry) : trim(iconv($enc, 'UTF-8', $entry));
					}
				);
			} //endforeach

			$this->csv[$path]['content'] = $csvArray;
			return true;
		}

		return false;
	}

	private function _buildHtml($path)
	{
		$fileName = $this->csv[$path]['fileName'];
		$tplName  = $this->csv[$path]['tplName'];
		$options  = array('csv' => $this->csv[$path]);
		$layout   = new FileLayout($fileName);

		$layout->setIncludePaths($this->layoutPath);
		$layout->setDebug(JDEBUG);

		if (!empty($this->_html = $layout->render($options)))
		{
			return true;
		}
		elseif ($this->_html = $layout->setLayoutId($tplName)->render($options))
		{
			return true;
		}

		return false;
	}

	private function _dbSaveCache($path)
	{
		$filetime = $this->csv[$path]['filetime'];

		if ($this->_readCsv($path))
		{
			if ($this->_buildHtml($path))
			{
				$db    = JFactory::getDBO();
				$query = $db->getQuery(true);

				$query->insert($db->quoteName('#__jtcsv2html'))
					->set(
						array(
							$db->quoteName('path') . '=' . $db->quote($path),
							$db->quoteName('filetime') . '=' . $db->quote($filetime),
							$db->quoteName('datas') . '=' . $db->quote($this->_html),
						)
					);
				$db->setQuery($query);

				return ($db->execute()) ? true : false;
			}
		}

		return false;
	}

	/**
	 * @param $tpl
	 */
	private function setCss($path)
	{
		$fileName = $this->csv[$path]['fileName'] . '.css';
		$tplName  = $this->csv[$path]['tplName'] . '.css';
		$realpath = realpath(JPATH_SITE);
		$cssPaths = str_replace($realpath . '/', '', $this->layoutPath);

		foreach ($this->layoutPath as $key => $layout)
		{

			if (file_exists($layout . '/' . $fileName))
			{
				if (!in_array($cssPaths[$key] . '/' . $fileName, $this->cssFiles))
				{
					$this->cssFiles[] = $cssPaths[$key] . '/' . $fileName;
				}

				return true;
			}

			if (file_exists($layout . '/' . $tplName))
			{
				if (!in_array($cssPaths[$key] . '/' . $tplName, $this->cssFiles))
				{
					$this->cssFiles[] = $cssPaths[$key] . '/' . $tplName;
				}

				return true;
			}
		}

		return false;
	}

	public function onContentAfterSave($context, $article, $isNew)
	{
		$this->_onContentEvent($context, $article, array('onSave' => true, 'isNew' => $isNew));
	}

	public function onContentBeforeDelete($context, $data)
	{
		$this->_onContentEvent($context, $data, array('onDelete' => true));
	}
}
