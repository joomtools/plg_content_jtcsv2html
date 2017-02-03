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


class plgContentJtcsv2html extends JPlugin
{

	private $pattern = '@(<[^>]+>|){jtcsv2html (.*)}(</[^>]+>|)@Usi';
	private $old_pattern = '@(<[^>]+>|){csv2html (.*)}(</[^>]+>|)@Usi';
	private $delimiter = null;
	private $enclosure = null;
	private $filter = null;
	private $cssFiles = array();
	private $_error = null;
	private $_matches = false;
	private $_csv = array();
	private $_html = '';

	private static $articleId;

	private static function getArticleId($id = null)
	{
		if (!empty($id))
		{
			self::$articleId = $id;
		}

		return self::$articleId;
	}

	public function __construct(&$subject, $params)
	{
		//if(!JFactory::getApplication()->isSite()) return;

		parent::__construct($subject, $params);

		$this->loadLanguage('plg_content_jtcsv2html');
		$app = JFactory::getApplication();

		if (version_compare(phpversion(), '5.3', '<'))
		{
			$app->enqueueMessage(JText::_('PLG_CONTENT_JTCSV2HTML_PHP_VERSION'), 'warning');

			return;
		}

		$this->delimiter = trim($this->params->get('delimiter', ','));
		$this->enclosure = trim($this->params->get('enclosure', '"'));
		$this->filter    = trim($this->params->get('filter', 0));

		if ($this->params->get('clearDB', 0))
		{
			$this->_dbClearAll();
		}
	}

	protected function _dbClearAll()
	{
		$db    = JFactory::getDBO();
		$query = $db->getQuery(true);

		// Zuruecksetzen des Parameters
		$this->params->set('clearDB', 0);
		$params = $this->params->toString();
		//$plgName = $this->_name;

		$query->clear();
		$query->update('#__extensions');
		$query->set('params=' . $db->quote($params));
		$query->where('name=' . $db->quote('PLG_CONTENT_JTCSV2HTML'));

		$db->setQuery($query);
		$db->execute();

		// Loeschen aller Daten aus der Datenbank
		$query->clear();
		$db->setQuery('TRUNCATE #__jtcsv2html');
		$db->execute();

		$query->clear();
		$db->setQuery('OPTIMIZE TABLE #__jtcsv2html');
		$db->execute();
	}

	public function onContentPrepare($context, &$article, &$params, $limitstart = 0)
	{
		if (!JFactory::getApplication()->isSite())
		{
			return;
		}

		$app = JFactory::getApplication();

		if (version_compare(phpversion(), '5.3', '<'))
		{
			return;
		}

		$_error       = ($context == 'com_content.search') ? false : true;
		$cache        = $this->params->get('cache', 1);
		$this->_error = $_error;

		/* Pluginaufruf auslesen */
		if ($this->_patterns($article->text) === false)
		{
			return;
		}

		while ($matches = each($this->_matches))
		{
			foreach ($matches[1] as $_matches)
			{
				$file = JPATH_SITE . '/images/jtcsv2html/' . $_matches['fileName'] . '.csv';

				$articleId = !empty($article->id) ? $article->id : null;

				$this->_csv['cid']      = self::getArticleId($articleId);
				$this->_csv['file']     = $file;
				$this->_csv['filename'] = $_matches['fileName'];
				$this->_csv['tplname']  = $_matches['tplName'];
				$this->_csv['filter']   = $_matches['filter'];
				$this->_csv['filetime'] = (file_exists($file)) ? filemtime($file) : -1;

				$this->_setTplPath();

				if ($this->_csv['filetime'] != -1)
				{
					if ($cache)
					{
						$setOutput = $this->_dbChkCache();
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
							JHtml::script('plugins/content/jtcsv2html/assets/plg_jtcsv2html_search.js', false, false);
						}
						$output .= $this->_html;
						$output .= '</div>';

						if (!class_exists('Minify_HTML'))
						{
							require_once 'assets/minifyHTML.inc';
						}
						$output = Minify_HTML::minify($output);

						$article->text = str_replace($_matches['replacement'], $output, $article->text);
					}
					else
					{
						if ($_error)
						{
							$app->enqueueMessage(
								JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $_matches['fileName'] . '.csv')
								, 'warning'
							);
						}
					}
				}
				else
				{
					if ($_error)
					{
						$app->enqueueMessage(
							JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $_matches['fileName'] . '.csv')
							, 'warning'
						);
					}

					$this->_dbClearCache();
				}
				unset($this->_csv, $this->_html);
			} //endforeach
		}

		if (count($this->cssFiles) > 0) $this->_loadCSS();
	}

	/**
	 * Wertet die Pluginaufrufe aus
	 *
	 * @param   string $article der Artikeltext
	 *
	 * @return  boolean
	 */
	protected function _patterns($article)
	{
		$return = false;
		$_match = array();

		$p1 = preg_match_all($this->pattern, $article, $matches1, PREG_SET_ORDER);
		$p2 = preg_match_all($this->old_pattern, $article, $matches2, PREG_SET_ORDER);

		switch (true)
		{
			case $p1 && $p2:
				$matches[] = $matches1;
				$matches[] = $matches2;
				break;
			case $p1:
				$matches[] = $matches1;
				break;
			case $p2:
				$matches[] = $matches2;
				break;
			default:
				$matches = false;
				break;
		}

		if ($matches)
		{
			while ($match = each($matches))
			{
				foreach ($match[1] as $key => $value)
				{
					$filter  = (boolean) $this->filter;
					$tplname = 'default';

					$_match[$key]['replacement'] = $value[0];

					if (strpos($value[2], ','))
					{
						$parameter = explode(',', $value[2], 3);
						$filename  = trim(strtolower($parameter[0]));
						$count     = count($parameter);

						if ($count >= 2)
						{
							$tplname = trim(strtolower($parameter[1]));
						}

						if ($count == 3)
						{
							if (trim(strtolower($parameter[2])) == 'on')
							{
								$filter = true;
							}
							elseif (trim(strtolower($parameter[2])) == 'off')
							{
								$filter = false;
							}
						}
					}
					else
					{
						$filename = $value[2];
					}

					$_match[$key]['fileName'] = $filename;
					$_match[$key]['tplName']  = $tplname;
					$_match[$key]['filter']   = $filter;
				}

				$this->_matches[] = $_match;
				$return           = true;
			}
		}

		return $return;
	}

	protected function _setTplPath()
	{
		$plgName  = $this->_name;
		$plgType  = $this->_type;
		$template = JFactory::getApplication()->getTemplate();

		$tpl['tpl']           = 'images/jtcsv2html/';
		$tpl['tplPlg']        = 'templates/' . $template . '/html/plg_' . $plgType . '_' . $plgName . '/';
		$tpl['plg']           = 'plugins/' . $plgType . '/' . $plgName . '/tmpl/';
		$tpl['tplDefault']    = 'images/jtcsv2html/default';
		$tpl['tplPlgDefault'] = 'templates/' . $template . '/html/plg_' . $plgType . '_' . $plgName . '/default';
		$tpl['default']       = 'plugins/' . $plgType . '/' . $plgName . '/tmpl/default';

		switch (true)
		{
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tplPlg'] . $this->_csv['tplname'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['tpl'] . $this->_csv['tplname'] . '.php';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['plg'] . $this->_csv['tplname'] . '.php'):
				$tplPath = JPATH_SITE . '/' . $tpl['plg'] . $this->_csv['tplname'] . '.php';
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
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . $this->_csv['filename'] . '.css'):
				$cssPath = JURI::root() . $tpl['tplPlg'] . $this->_csv['filename'] . '.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . $this->_csv['filename'] . '.css'):
				$cssPath = JURI::root() . $tpl['tpl'] . $this->_csv['filename'] . '.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlg'] . $this->_csv['tplname'] . '.css'):
				$cssPath = JURI::root() . $tpl['tplPlg'] . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tpl'] . $this->_csv['tplname'] . '.css'):
				$cssPath = JURI::root() . $tpl['tpl'] . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['plg'] . $this->_csv['tplname'] . '.css'):
				$cssPath = JURI::root() . $tpl['plg'] . $this->_csv['tplname'] . '.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplPlgDefault'] . '.css'):
				$cssPath = JURI::root() . $tpl['tplPlgDefault'] . '.css';
				break;
			case file_exists(JPATH_SITE . '/' . $tpl['tplDefault'] . '.css'):
				$cssPath = JURI::root() . $tpl['tplDefault'] . '.css';
				break;
			default:
				$cssPath = JURI::root() . $tpl['default'] . '.css';
				break;
		}

		$this->_csv['tplPath']    = $tplPath;
		$this->cssFiles[$cssFile] = $cssPath;
	}

	protected function _dbChkCache()
	{
		$db = JFactory::getDBO();

		if ($cid = $this->_csv['cid'] == '')
		{
			return;
		}

		$filename = $this->_csv['filename'];
		$tplname  = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$dbAction = null;
		$query    = $db->getQuery(true);

		$query->clear();
		$query->select('filetime, id');
		$query->from('#__jtcsv2html');
		$query->where('cid=' . $db->quote($cid));
		$query->where('filename=' . $db->quote($filename));
		$query->where('tplname=' . $db->quote($tplname));

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
				$return = $this->_dbLoadCache();
				break;
			case ($dbAction == 'update'):
				$return = $this->_dbUpdateCache($id);
				break;
			default:
				$return = $this->_dbSaveCache();
				break;
		}

		return $return;
	}

	protected function _dbLoadCache()
	{
		$return = false;

		if ($cid = $this->_csv['cid'] == '')
		{
			return;
		}

		$db    = JFactory::getDBO();
		$query = $db->getQuery(true);

		$filename = $this->_csv['filename'];
		$tplname  = $this->_csv['tplname'];

		$query->clear();
		$query->select('datas');
		$query->from('#__jtcsv2html');
		$query->where('cid=' . $db->quote($cid));
		$query->where('filename=' . $db->quote($filename));
		$query->where('tplname=' . $db->quote($tplname));

		$db->setQuery($query);
		$result = $db->loadResult();

		if ($result !== null)
		{
			$this->_html = $result;
			$return      = true;
		}

		return $return;
	}

	protected function _dbUpdateCache($id)
	{
		$return = false;

		if ($cid = $this->_csv['cid'] == '')
		{
			return;
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
			$dbFile = $db->execute();

			$return = ($dbFile) ? true : false;
		}

		return $return;
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
							function (&$entry)
							{
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
						function (&$entry)
						{
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

		if ($cid = $this->_csv['cid'] == '')
		{
			return;
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
			$document = JFactory::getDocument();
			$document->addStyleSheet($cssFile, 'text/css');
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
