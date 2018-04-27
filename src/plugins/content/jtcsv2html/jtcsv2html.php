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
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Profiler\Profiler;

class plgContentJtcsv2html extends CMSPlugin
{
	const PLUGIN_REGEX = '@(<(\w+)[^>]*>)?{jtcsv2html (.*)}(</\\2>)?@';
	const PLUGIN_REGEX_OLD = '@(<(\w+)[^>]*>)?{csv2html (.*)}(</\\2>)?@';
	private static $articleId;
	protected $app;
	protected $autoloadLanguage = true;
	private $delimiter = null;
	private $enclosure = null;
	private $filter = null;
	private $cssFiles = [];
	private $_error = null;
	private $_matches = [];
	private $_csv = [];
	private $_html = '';

	public function __construct(&$subject, $params)
	{
		parent::__construct($subject, $params);

		if ($this->app->isClient('administrator'))
		{
			if ($this->params->get('clearDB', 0))
			{
				$this->_dbClearAll();
			}
		}
	}

	protected function _dbClearAll()
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
		$db->setQuery('TRUNCATE ' . $db->quoteName('#__jtcsv2html'));
		$db->execute();

		$db->setQuery('OPTIMIZE TABLE ' . $db->quoteName('#__jtcsv2html'));
		$db->execute();
	}

	public function onContentPrepare($context, &$article, &$params, $limitstart = 0)
	{
		if (strpos($article->text, '{jtcsv2html') === false
			&& strpos($article->text, '{csv2html') === false
			|| $context == 'com_finder.indexer'
			|| $this->app->isClient('administrator')
		)
		{
			return;
		}

		/* Pluginaufruf auslesen */
		if ($this->_patterns($article->text) === false)
		{
			return;
		}

		$this->delimiter = trim($this->params->get('delimiter', ','));
		$this->enclosure = trim($this->params->get('enclosure', '"'));
		$this->filter    = trim($this->params->get('filter', 0));

		$_error       = ($context == 'com_content.search') ? false : true;
		$cache        = $this->params->get('cache', 1);
		$this->_error = $_error;

		foreach ($this->_matches as $match)
		{
			$file = JPATH_SITE . '/images/jtcsv2html/' . $match['fileName'] . '.csv';

			$articleId = !empty($article->id) ? $article->id : null;

			$this->_csv['cid']      = self::getArticleId($articleId);
			$this->_csv['file']     = $file;
			$this->_csv['filename'] = $match['fileName'];
			$this->_csv['tplname']  = $match['tplName'];
			$this->_csv['filter']   = $match['filter'];
			$this->_csv['filetime'] = (file_exists($file)) ? filemtime($file) : -1;

			$this->_setTplPath();

			if ($this->_csv['filetime'] != -1)
			{
				if ($cache)
				{
					$setOutput = $this->getDbCache();
				}
				else
				{
					$setOutput = $this->_readCsv();
					if ($setOutput) $this->_buildHtml();
				}

				/* Plugin-Aufruf durch HTML-Ausgabe ersetzen */
				if ($setOutput)
				{
					$output = '<div class="jtcsv2html_wrapper">';

					if ($this->_csv['filter'])
					{
						$output .= '<input type="text" class="search" placeholder="Type to search">';
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

					$article->text = str_replace($match['replacement'], $output, $article->text);
				}
				else
				{
					if ($_error)
					{
						$this->app->enqueueMessage(
							JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $match['fileName'] . '.csv')
							, 'warning'
						);
					}
				}
			}
			else
			{
				if ($_error)
				{
					$this->app->enqueueMessage(
						JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $match['fileName'] . '.csv')
						, 'warning'
					);
				}

				$this->_dbClearCache();
			}
			unset($this->_csv, $this->_html);
		} //endforeach

		if (count($this->cssFiles) > 0) $this->_loadCSS();

		// Set profiler start time and memory usage and mark afterLoad in the profiler.
		JDEBUG ? Profiler::getInstance('Application')->mark('plgContentJtcsv2html') : null;
	}

	/**
	 * Wertet die Pluginaufrufe aus
	 *
	 * @param   string $article der Artikeltext
	 *
	 * @return   bool
	 *
	 * @since   3.0.1
	 */
	protected function _patterns($article)
	{
		$return = false;

		$p1 = preg_match_all(self::PLUGIN_REGEX, $article, $matches1, PREG_SET_ORDER);
		$p2 = preg_match_all(self::PLUGIN_REGEX_OLD, $article, $matches2, PREG_SET_ORDER);

		if ($p1)
		{
			$return = $this->setMatches($matches1);
		}

		if ($p2)
		{
			$_return = $this->setMatches($matches2);
			$return  = $return ? $return : $_return;
		}

		return $return;
	}

	/**
	 * @param $matches
	 *
	 * @return   bool
	 *
	 * @since   3.0.1
	 */
	protected function setMatches($matches)
	{
		$index = count($this->_matches);

		foreach ($matches as $match)
		{
			$filter  = filter_var($this->filter, FILTER_VALIDATE_BOOLEAN);
			$tplname = 'default';

			$this->_matches[$index]['replacement'] = $match[0];

			if (strpos($match[3], ','))
			{
				$callParams      = explode(',', $match[3], 3);
				$filename        = trim(strtolower($callParams[0]));
				$countCallParams = count($callParams);

				if ($countCallParams >= 2)
				{
					$tplname = trim(strtolower($callParams[1]));
				}

				if ($countCallParams == 3)
				{
					if (trim(strtolower($callParams[2])) == 'on')
					{
						$filter = true;
					}
					elseif (trim(strtolower($callParams[2])) == 'off')
					{
						$filter = false;
					}
				}
			}
			else
			{
				$filename = $match[3];
			}

			$this->_matches[$index]['fileName'] = $filename;
			$this->_matches[$index]['tplName']  = $tplname;
			$this->_matches[$index]['filter']   = $filter;

			$index++;
		}

		if (!empty($this->_matches))
		{
			return true;
		}

		return false;
	}

	private static function getArticleId($id = null)
	{
		if (!empty($id))
		{
			self::$articleId = $id;
		}

		return self::$articleId;
	}

	protected function _setTplPath()
	{
		$plgName  = $this->_name;
		$plgType  = $this->_type;
		$template = $this->app->getTemplate();

		$tpl['tpl']           = 'images/jtcsv2html';
		$tpl['tplPlg']        = 'templates/' . $template . '/html/plg_' . $plgType . '_' . $plgName;
		$tpl['plg']           = 'plugins/' . $plgType . '/' . $plgName . '/tmpl';
		$tpl['tplDefault']    = 'images/jtcsv2html/default';
		$tpl['tplPlgDefault'] = 'templates/' . $template . '/html/plg_' . $plgType . '_' . $plgName . '/default';
		$tpl['default']       = 'plugins/' . $plgType . '/' . $plgName . '/tmpl/default';

		switch (true)
		{
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . '/' . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tplPlg'] . '/' . $this->_csv['tplname'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . '/' . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tpl'] . '/' . $this->_csv['tplname'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['plg'] . '/' . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['plg'] . '/' . $this->_csv['tplname'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlgDefault'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tplPlgDefault'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplDefault'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tplDefault'] . '.php';
				break;
			default:
				$tplPath = JPATH_SITE . '/' . $tpl['default'] . '.php';
				break;
		}

		$cssFile = 'default';
		switch (true)
		{
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . '/' . $this->_csv['filename'] . '.css'):
				$cssPath = $tpl['tplPlg'] . '/' . $this->_csv['filename'] . '.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . '/' . $this->_csv['filename'] . '.css'):
				$cssPath = $tpl['tpl'] . '/' . $this->_csv['filename'] . '.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . '/' . $this->_csv['tplname'] . '.css'):
				$cssPath = $tpl['tplPlg'] . '/' . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . '/' . $this->_csv['tplname'] . '.css'):
				$cssPath = $tpl['tpl'] . '/' . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['plg'] . '/' . $this->_csv['tplname'] . '.css'):
				$cssPath = $tpl['plg'] . '/' . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlgDefault'] . '.css'):
				$cssPath = $tpl['tplPlgDefault'] . '.css';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplDefault'] . '.css'):
				$cssPath = $tpl['tplDefault'] . '.css';
				break;
			default:
				$cssPath = $tpl['default'] . '.css';
				break;
		}

		$this->_csv['tplPath'] = $tplPath;
		JHtml::_('stylesheet', $cssPath, array('version' => 'auto'));

//		$this->cssFiles[$cssFile] = $cssPath;
	}

	protected function getDbCache()
	{
		$db  = Factory::getDBO();
		$cid = $this->_csv['cid'];

		if (empty($cid))
		{
			return false;
		}

		$filename = $this->_csv['filename'];
		$tplname  = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$dbAction = null;

		$query = $db->getQuery(true);

		$query->select($db->quoteName(array('filetime', 'id', 'datas')));
		$query->from($db->quoteName('#__jtcsv2html'));
		$query->where(
			array(
				$db->quoteName('cid') . '=' . $db->quote($cid),
				$db->quoteName('filename') . '=' . $db->quote($filename),
				$db->quoteName('tplname') . '=' . $db->quote($tplname),
			)
		);

		$db->setQuery($query);

		$result     = $db->loadAssoc();
		$dbFiletime = $result['filetime'];
		$id         = $result['id'];

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
				return $this->_dbUpdateCache($id);
				break;
			default:
				return $this->_dbSaveCache();
				break;
		}
	}

	protected function _dbUpdateCache($id)
	{
		$cid = $this->_csv['cid'];

		if (empty($cid))
		{
			return false;
		}

		$filename = $this->_csv['filename'];
		$tplname  = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$db       = JFactory::getDBO();
		$query    = $db->getQuery(true);

		if ($this->_readCsv())
		{
			$this->_buildHtml();

			$query->update('#__jtcsv2html');
			$query->set('cid=' . $db->quote($cid));
			$query->set('filename=' . $db->quote($filename));
			$query->set('tplname=' . $db->quote($tplname));
			$query->set('filetime=' . $db->quote($filetime));
			$query->set('datas=' . $db->quote($this->_html));
			$query->where('id=' . $db->quote($id));

			$db->setQuery($query);

			return ($db->execute()) ? true : false;
		}

		return false;
	}

	protected function _readCsv()
	{
		$return = false;
		$csv    = &$this->_csv;
		$file   = $csv['file'];

		if ($this->delimiter == 'null')
		{
			$this->delimiter = ' ';
		}

		if ($this->delimiter == '\t')
		{
			$this->delimiter = "\t";
		}

		if (version_compare(phpversion(), '5.3', '<'))
		{
			if (file_exists($file) || is_readable($file))
			{
				$data = array();

				if (($handle = fopen($file, 'r')) !== false)
				{
					$filesize = filesize($file);

					while (($row = fgetcsv($handle, $filesize, $this->delimiter, $this->enclosure)) !== false)
					{
						array_walk($row,
							function (&$entry) {
								$enc   = mb_detect_encoding($entry, "UTF-8,ISO-8859-1,WINDOWS-1252");
								$entry = ($enc == 'UTF-8') ? trim($entry) : trim(iconv($enc, 'UTF-8', $entry));
							}
						);

						$setDatas = false;

						foreach ($row as $value)
						{
							if ($value != '') $setDatas = true;
						}

						if ($setDatas) $data[] = $row;
					} //endwhile

					fclose($handle);

					$csv['datas'] = $data;
					$return       = true;
				} //endfopen
			} //end file_exist
		}
		else
		{
			if (file_exists($file) || is_readable($file))
			{
				$csvArray = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

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

				$csv['datas'] = $csvArray;
				$return       = true;
			} //end file_exist
		} //endif phpversion

		return $return;
	}

	protected function _buildHtml()
	{
		ob_start();
		require $this->_csv['tplPath'];

		$this->_html = ob_get_clean();
	}

	protected function _dbSaveCache()
	{
		$return = false;
		$cid    = $this->_csv['cid'];

		if (empty($cid))
		{
			return false;
		}

		$filename = $this->_csv['filename'];
		$tplname  = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$db       = JFactory::getDBO();
		$query    = $db->getQuery(true);

		if ($this->_readCsv())
		{
			$this->_buildHtml();
			$query->clear();
			$columns = 'cid, filename, tplname, filetime, datas';
			$values  = $db->quote($cid)
				. ',' . $db->quote($filename)
				. ',' . $db->quote($tplname)
				. ',' . $db->quote($filetime)
				. ',' . $db->quote($this->_html);

			$query->insert($db->quoteName('#__jtcsv2html'));
			$query->columns($columns);
			$query->values($values);
			$db->setQuery($query);

			$return = $db->execute();
		}

		return $return;
	}

	protected function _dbClearCache($onSave = false)
	{
		$db       = JFactory::getDBO();
		$cid      = $this->_csv['cid'];
		$filename = $this->_csv['filename'];
//		$tplname  = $this->_csv['tplname'];
		$query = $db->getQuery(true);

		$query->clear();
		$query->delete('#__jtcsv2html');

		if (!$onSave)
		{
			$query->where('filename=' . $db->quote($filename));
//			$query->where('tplname=' . $db->quote($tplname));
		}
		else
		{
			$query->where('cid=' . $db->quote($cid));
		}

		$db->setQuery($query);
		$return = $db->execute();

		$query->clear();
		$db->setQuery('OPTIMIZE TABLE #__jtcsv2html_data');
		$db->execute();

		return $return;
	}

	protected function _loadCSS()
	{
		$cssFiles = $this->cssFiles;

		foreach ($cssFiles as $cssFile)
		{
			JHtml::_('stylesheet', $cssFile, array('version' => 'auto'));
		}
	}

	public function onContentAfterSave($context, $article, $isNew)
	{
		$this->_onContentEvent($context, $article, $isNew);
	}

	protected function _onContentEvent($context, $article, $isNew = false)
	{
		if (version_compare(phpversion(), '5.3', '<') || $isNew)
		{
			return;
		}

		$this->_csv['cid'] = $article->id;
		$this->_dbClearCache(true);
	}

	public function onContentBeforeDelete($context, $data)
	{
		$this->_onContentEvent($context, $data);
	}
}
