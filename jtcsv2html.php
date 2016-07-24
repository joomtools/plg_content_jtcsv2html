<?php
/**
 * @Copyright  JoomTools
 * @package    JT - Csv2Html - Plugin for Joomla! 2.5.x - 3.x
 * @author     Guido De Gobbis
 * @link       http://www.joomtools.de
 *
 * @license    GNU/GPL <http://www.gnu.org/licenses/>
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
	private $delimiter = NULL;
	private $enclosure = NULL;
	private $cssFiles = array();
	private $_error = NULL;
	private $_matches = false;
	private $_csv = array();
	private $_html = '';
	private $FB = false;

	public function __construct(&$subject, $params)
	{
		//if(!JFactory::getApplication()->isSite()) return;

		parent::__construct($subject, $params);

		$this->loadLanguage('plg_content_jtcsv2html');
		$app = JFactory::getApplication();

		if (version_compare(phpversion(), '5.3', '<')) {
			$app->enqueueMessage(JText::_('PLG_CONTENT_JTCSV2HTML_PHP_VERSION'), 'warning');
			return;
		}

		$this->delimiter = trim($this->params->get('delimiter', ','));
		$this->enclosure = trim($this->params->get('enclosure', '"'));

		if(defined('JFIREPHP') && $this->params->get('debug', 0)) {
			$this->FB = FirePHP::getInstance(true);
		} else {
			$this->FB = false;
		}

		if($this->params->get('clearDB', 0)) {
			if($this->FB) $this->FB->group(__FUNCTION__ . '()', array('Collapsed' => true, 'Color' => '#6699CC'));
			$this->_dbClearAll();
			if($this->FB) $this->FB->groupEnd();

		}
	}

	public function onContentPrepare($context, &$article, &$params, $limitstart)
	{
		if(!JFactory::getApplication()->isSite()) return;
		$app = JFactory::getApplication();

		if (version_compare(phpversion(), '5.3', '<')) {
			return;
		}

		/* Prüfen ob Plugin-Platzhalter im Text ist */
//		if(!preg_match($this->pattern, $article->text) && !preg_match($this->old_pattern, $article->text)) return;

		$FB = $this->FB;
		$_error = ($context == 'com_content.search') ? false : true;
		$cache = $this->params->get('cache', 1);
		$this->_error = $_error;
		$timestart = microtime(true);

		if($FB) $FB->group('Plugin - JT - Csv2Html', array('Collapsed' => false, 'Color' => '#6699CC'));

		if($FB) $FB->log($context, '$context');
		if($FB) $FB->log($article, '$article');
		if($FB) $FB->log($params, '$params');
		if($FB) $FB->log($limitstart, '$limitstart');
		if($FB) $FB->log($_error, '$_error');
		/* Pluginaufruf auslesen */
		if($this->_patterns($article->text) === false) {
			if($FB) $FB->groupEnd();
			return ;
		}

		while($matches = each($this->_matches))
		{
			foreach($matches[1] as $_matches)
			{
				$timestamp1 = microtime(true);
				$file = JPATH_SITE . '/images/jtcsv2html/' . $_matches['fileName'] . '.csv';

				if($FB) {
					$FB->group('Aufruf der Datei ' . $_matches['fileName'] . '.csv', array('Collapsed' => true,
					                                                                       'Color' => '#6699CC'));
					$FB->log($file, 'Dateipfad');
				}

				$this->_csv['cid'] = $article->id;
				$this->_csv['file'] = $file;
				$this->_csv['filename'] = $_matches['fileName'];
				$this->_csv['tplname'] = $_matches['tplName'];
				$this->_csv['filetime'] = (file_exists($file)) ? filemtime($file) : -1;

				$this->_setTplPath();

				if($this->_csv['filetime'] != -1)
				{
					if($cache)
					{
						$setOutput = $this->_dbChkCache();
					}
					else
					{
						$setOutput = $this->_readCsv();
						if($setOutput) $this->_buildHtml();
					}

					/* Plugin-Aufruf durch HTML-Ausgabe ersetzen */
					if($setOutput)
					{
						$article->text = str_replace($_matches['replacement'], $this->_html, $article->text);
					}
					else
					{
						if($_error)
						{
							if($FB) $FB->warn('Fehler trotz CSV-Datei.');

							$app->enqueueMessage(
									JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $_matches['fileName'].'.csv')
									, 'warning'
							);
						}
					}
				}
				else
				{
					if($FB) $FB->warn('CSV-Datei nicht gefunden.');

					if($_error) {
						$app->enqueueMessage(
								JText::sprintf('PLG_CONTENT_JTCSV2HTML_NOCSV', $_matches['fileName'].'.csv')
								, 'warning'
						);
					}
					$this->_dbClearCache();
				}
				unset($this->_csv, $this->_html);
				$timestamp2 = microtime(true);
				$timestamp = $timestamp2-$timestamp1;

				if($FB) $FB->info($timestamp, 'verbrauchte Zeit in Sek.');

				if($FB) $FB->groupEnd();
			} //endforeach
		}

		if(count($this->cssFiles) > 0) $this->_loadCSS();

		$timeend = microtime(true);
		$timetotal = $timeend-$timestart;

		if($FB) $FB->info($timetotal, 'verbrauchte Zeit in Sek.');
		if($FB) $FB->groupEnd();
	}

	public function onContentAfterSave($context, $article, $isNew)
	{
		$this->_onContentEvent($context, $article, $isNew);
	}

	public function onContentBeforeDelete($context, $data)
	{
		$this->_onContentEvent($context, $data);
	}

	protected function _onContentEvent($context, $article, $isNew=false)
	{
		if (version_compare(phpversion(), '5.3', '<') || $isNew) {
			return;
		}

		$FB = $this->FB;
		$this->_csv['cid'] = $article->id;

		if($FB) $FB->group(
				'Plugin - JT - Csv2Html => ' . __FUNCTION__ . '()'
				, array('Collapsed' => false, 'Color' => '#6699CC')
		);

		if($FB) $FB->log($context, '$context');
		if($FB) $FB->log($article, '$article');
		if($FB) $FB->log($isNew, '$isNew');

		$dbClearCache = $this->_dbClearCache(true);

		if($FB) $FB->log($dbClearCache, '$dbClearCache');

		if($FB) $FB->groupEnd();
	}

	/**
	 * Wertet die Pluginaufrufe aus
	 *
	 * @param   string  $article  der Artikeltext
	 *
	 * @return  boolean
	 */
	protected function _patterns($article)
	{
		$FB = $this->FB;
		$return = false;
		$_match = array();

		if($FB) $FB->group(
				__FUNCTION__ . '()'
				, array('Collapsed' => true, 'Color' => '#6699CC')
		);

		if($FB) $FB->log($article, '$article');

		$p1 = preg_match_all($this->pattern, $article, $matches1, PREG_SET_ORDER);
		$p2 = preg_match_all($this->old_pattern, $article, $matches2, PREG_SET_ORDER);

		switch(true)
		{
			case $p1 && $p2:
				$matches[] = $matches1;
				$matches[] = $matches2;
				if($FB) $FB->log($matches1, 'Aufruf -> jtcsv2html');
				if($FB) $FB->log($matches2, 'Aufruf -> csv2html');
				break;
			case $p1:
				$matches[] = $matches1;
				if($FB) $FB->log($matches1, 'Aufruf -> jtcsv2html');
				break;
			case $p2:
				$matches[] = $matches2;
				if($FB) $FB->log($matches2, 'Aufruf -> csv2html');
				break;
			default:
				$matches = false;
				break;
		}

		if($FB) $FB->log($matches, '$matches');

		if($matches)
		{
			while($match = each($matches))
			{
				foreach($match[1] as $key => $value)
				{
					if($FB) $FB->log($value, '$value');
					$tplname = null;

					$_match[$key]['replacement'] = $value[0];
					if(strpos($value[2], ','))
					{
						list($filename, $_rest) = explode(',', $value[2], 2);
						if(strpos($_rest, ','))
						{
							list($tplname, $rest) = explode(',', $_rest);
						}
						elseif ($_rest != "")
						{
							$tplname = $_rest;
						}
					}
					else
					{
						$filename = $value[2];
					}
					$_match[$key]['fileName'] = trim($filename);
					$_match[$key]['tplName'] = ($tplname) ? trim($tplname) : trim($filename);
				}

				$this->_matches[] = $_match;
				$return = true;
			}
		}

		if($FB) {
			$FB->log($this->_matches, '$this->_matches');
			$FB->log($return, '$return');

			if($return) {
				$FB->info(__FUNCTION__ . '() => wurde ordnungsgemäß ausgeführt');
			}
			else {
				$FB->warn('Probleme beim Identifizieren des Pluginaufrufes in der Methode "_patterns"');
			}

			$FB->groupEnd();
		}

		return $return;

	}

	protected function _setTplPath()
	{
		$FB = $this->FB;
		if($FB) $FB->group(__FUNCTION__.'()', array('Collapsed' => true, 'Color' => '#6699CC'));

		$plgName = $this->_name;
		$plgType = $this->_type;
		$template = JFactory::getApplication()->getTemplate();

		$tpl['tpl'] = 'images/jtcsv2html/';
		$tpl['tplPlg'] = 'templates/'.$template.'/html/plg_'.$plgType.'_'.$plgName.'/';
        $tpl['plg'] = 'plugins/'.$plgType.'/'.$plgName.'/tmpl/';
		$tpl['tplDefault'] = 'images/jtcsv2html/default';
		$tpl['tplPlgDefault'] = 'templates/'.$template.'/html/plg_'.$plgType.'_'.$plgName.'/default';
		$tpl['default'] = 'plugins/'.$plgType.'/'.$plgName.'/tmpl/default';

		if($FB) $FB->info($tpl, '$theme');

		switch(true)
		{
			case file_exists(JPATH_SITE.'/'.$tpl['tpl'].$this->_csv['tplname'].'.php'):
				$tplPath = JPATH_SITE.'/'.$tpl['tpl'].$this->_csv['tplname'].'.php';
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplPlg'].$this->_csv['tplname'].'.php'):
				$tplPath = JPATH_SITE.'/'.$tpl['tplPlg'].$this->_csv['tplname'].'.php';
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['plg'].$this->_csv['tplname'].'.php'):
				$tplPath = JPATH_SITE.'/'.$tpl['plg'].$this->_csv['tplname'].'.php';
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplDefault'].'.php'):
				$tplPath = JPATH_SITE.'/'.$tpl['tplDefault'].'.php';
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplPlgDefault'].'.php'):
				$tplPath = JPATH_SITE.'/'.$tpl['tplPlgDefault'].'.php';
				break;
			default:
				$tplPath = JPATH_SITE.'/'.$tpl['default'].'.php';
				break;
		}

		if($FB) $FB->log($tplPath, '$tplPath');

		$cssFile = 'default';
		switch(true)
		{
			case file_exists(JPATH_SITE.'/'.$tpl['tpl'].$this->_csv['filename'].'.css'):
				$cssPath = JURI::root().$tpl['tpl'].$this->_csv['filename'].'.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplPlg'].$this->_csv['filename'].'.css'):
				$cssPath = JURI::root().$tpl['tplPlg'].$this->_csv['filename'].'.css';
				$cssFile = $this->_csv['filename'];
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['plg'].$this->_csv['tplname'].'.css'):
				$cssPath = JURI::root().$tpl['plg'].$this->_csv['tplname'].'.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplPlg'].$this->_csv['tplname'].'.css'):
				$cssPath = JURI::root().$tpl['tplPlg'].$this->_csv['tplname'].'.css';
				$cssFile = $this->_csv['tplname'];
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplDefault'].'.css'):
				$cssPath = JURI::root().$tpl['tplDefault'].'.css';
				break;
			case file_exists(JPATH_SITE.'/'.$tpl['tplPlgDefault'].'.css'):
				$cssPath = JURI::root().$tpl['tplPlgDefault'].'.css';
				break;
			default:
				$cssPath = JURI::root().$tpl['default'].'.css';
				break;
		}

		$this->_csv['tplPath'] = $tplPath;
		$this->cssFiles[$cssFile] = $cssPath;

		if($FB) $FB->log($cssPath, '$cssPath');
		if($FB) $FB->log($this->cssFiles, '$this->cssFiles');

		if($FB) {
			$FB->info(__FUNCTION__.'() => wurde ordnungsgemäß ausgeführt');
			$FB->groupEnd();
		}
	}

	protected function _dbChkCache()
	{
		$FB = $this->FB;
		$db = JFactory::getDBO();
		$cid = $this->_csv['cid'];
		$filename = $this->_csv['filename'];
		$tplname = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$dbAction = null;
		$query = $db->getQuery(true);

		if($FB) $FB->group(__FUNCTION__ . '()', array('Collapsed' => false, 'Color' => '#6699CC'));

		$query->clear();
		$query->select('filetime, id');
		$query->from('#__jtcsv2html');
		$query->where('cid=' . $db->quote($cid));
		$query->where('filename=' . $db->quote($filename));
		$query->where('tplname=' . $db->quote($tplname));

		$db->setQuery($query);
		$result = $db->loadAssoc();
		$dbFiletime = $result['filetime'];
		$id = $result['id'];

		if($FB) $FB->info($dbFiletime, '$dbFiletime');
		if($FB) $FB->info($id, '$id');

		if($dbFiletime !== NULL) $dbAction = (($filetime - $dbFiletime) <= 0) ? 'load' : 'update';

		if($FB) $FB->info($dbAction, '$dbAction');

		switch(true)
		{
			case ($dbAction == 'load'):
				$return = $this->_dbLoadCache();
				break;
			case ($dbAction == 'update'):
				$return = $this->_dbUpdateCache($id);
				break;
			default:
				$return = $this->_dbSaveCache();
		}


		if($FB) {
			$FB->log($return, '$return');
			$FB->info(__FUNCTION__ . '() => wurde ordnungsgemäß ausgeführt');
			$FB->groupEnd();
		}

		return $return;
	}

	protected function _dbLoadCache()
	{
		$FB = $this->FB;
		$return = false;
		$cid = $this->_csv['cid'];
		$db = JFactory::getDBO();
		$query = $db->getQuery(true);

		if($FB) $FB->group(__FUNCTION__ . '()', array('Collapsed' => false, 'Color' => '#6699CC'));

		$filename = $this->_csv['filename'];
		$tplname = $this->_csv['tplname'];

		$query->clear();
		$query->select('datas');
		$query->from('#__jtcsv2html');
		$query->where('cid=' . $db->quote($cid));
		$query->where('filename=' . $db->quote($filename));
		$query->where('tplname=' . $db->quote($tplname));

		$db->setQuery($query);
		$result = $db->loadResult();

		if($FB) $FB->log($result, '$result');

		if($result !== NULL) {
			$this->_html = $result;
			$return = TRUE;
		}

		if($FB) {
			$FB->log($return, '$return');

			if($return) {
				$FB->info(__FUNCTION__.'() => wurde ordnungsgemäß ausgeführt.');
			}
			else {
				$FB->warn(__FUNCTION__.'() => Daten konnten nicht gelesen werden.');
			}

			$FB->groupEnd();
		}

		return $return;
	}

	protected function _dbUpdateCache($id)
	{
		$FB = $this->FB;
		$return = false;
		$cid = $this->_csv['cid'];
		$filename = $this->_csv['filename'];
		$tplname = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$db = JFactory::getDBO();
		$query = $db->getQuery(true);

		if($FB) {
			$FB->group(__FUNCTION__ . '()', array('Collapsed' => true, 'Color' => '#6699CC'));
		}

		if($this->_readCsv()) {
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

		if($FB) {
			$FB->log($return, '$return');

			if($return) {
				$FB->info(__FUNCTION__ . '() => wurde ordnungsgemäß ausgeführt.');
			}
			else {
				$FB->warn(__FUNCTION__ . '() => Datenbank konnte nicht aktualisiert werden.');
			}

			$FB->groupEnd();
		}

		return $return;
	}

	protected function _dbSaveCache()
	{
		$FB = $this->FB;
		$return = false;
		$cid = $this->_csv['cid'];
		$filename = $this->_csv['filename'];
		$tplname = $this->_csv['tplname'];
		$filetime = $this->_csv['filetime'];
		$db = JFactory::getDBO();
		$query = $db->getQuery(true);

		if($FB) $FB->group(__FUNCTION__ . '()', array('Collapsed' => true, 'Color' => '#6699CC'));

		if($this->_readCsv())
		{
			$this->_buildHtml();
			$query->clear();
			$columns = 'cid, filename, tplname, filetime, datas';
			$values = $db->quote($cid)
			          . ',' . $db->quote($filename)
			          . ',' . $db->quote($tplname)
			          . ',' . $db->quote($filetime)
			          . ',' . $db->quote($this->_html);

			$query->insert($db->quoteName('#__jtcsv2html'));
			$query->columns($columns);
			$query->values($values);
			$db->setQuery($query);

			if($FB) $FB->log((string)$db->getQuery(), '$db->getQuery()');

			$return = $db->execute();
		}

		if($FB) {
			$FB->log($return, '$return');

			if($return) {
				$FB->info(__FUNCTION__ . '() => wurde ordnungsgemäß ausgeführt.');
			} else {
				$FB->warn(__FUNCTION__ . '() => fehler beim Speichern in der Datenbank.');
			}

			$FB->groupEnd();
		}

		return $return;
	}

	protected function _dbClearCache($onSave=false)
	{
		$FB = $this->FB;
		$db = JFactory::getDBO();
		$cid = $this->_csv['cid'];
		$filename = $this->_csv['filename'];
		$tplname = $this->_csv['tplname'];
		$query = $db->getQuery(true);

		if($FB) $FB->group(__FUNCTION__ . '()', array('Collapsed' => false, 'Color' => '#6699CC'));

		$query->clear();
		$query->delete('#__jtcsv2html');
		if(!$onSave)
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

		if($FB) $FB->log((string)$db->getQuery(), '$query');


		$query->clear();
		$db->setQuery('OPTIMIZE TABLE #__jtcsv2html_data');
		$optimize = $db->execute();

		if($FB) {
			$FB->info($return, 'Datenbank bereinigt');
			$FB->info($optimize, 'Datenbank optimiert');
			$FB->groupEnd();
		}

		return $return;
	}

	protected function _loadCSS()
	{
		$FB = $this->FB;
		$cssFiles = $this->cssFiles;
		if($FB) $FB->group(__FUNCTION__.'()', array('Collapsed' => true, 'Color' => '#6699CC'));

		foreach($cssFiles as $cssFile) {
			$document = JFactory::getDocument();
			$document->addStyleSheet($cssFile, 'text/css');
			if($FB) $FB->log($cssFile, '$cssFile');
		}

		if($FB) {
			$FB->info(__FUNCTION__.'() => wurde ordnungsgemäß ausgeführt');
			$FB->groupEnd();
		}
	}

	protected function _readCsv()
	{
		$FB = $this->FB;
		$return = false;
		$csv = &$this->_csv;
		$file = $csv['file'];
		if($this->delimiter == 'null') $this->delimiter = ' ';
		if($this->delimiter == '\t') $this->delimiter = "\t";

		if($FB) {
			$FB->group(__FUNCTION__.'()', array('Collapsed' => true, 'Color' => '#6699CC'));
			$FB->info($this->delimiter, '$this->delimiter');
			$FB->info($this->enclosure, '$this->enclosure');
		}

		if (version_compare(phpversion(), '5.3', '<'))
		{
			if($FB) $FB->info('CSV-Daten aufbereiten in PHP-Version < 5.3');

			if(file_exists($file) || is_readable($file))
			{
				$data = array();

				if(($handle = fopen($file, 'r')) !== false)
				{
					$filesize = filesize($file);

					while(($row = fgetcsv($handle, $filesize, $this->delimiter, $this->enclosure)) !== false)
					{
						array_walk($row,
								function (&$entry) {
									$enc = mb_detect_encoding($entry, "UTF-8,ISO-8859-1,WINDOWS-1252");
									$entry = ($enc == 'UTF-8') ? trim($entry) : trim(iconv($enc, 'UTF-8', $entry));
								}
						);

						$setDatas = false;

						foreach($row as $value) {
							if($value != '') $setDatas = true;
						}

						if($setDatas) $data[] = $row;
					} //endwhile

					fclose($handle);

					$csv['datas'] = $data;
					$return = true;

					if($FB) $FB->log($csv['datas'], '$csv["datas"]');
				} //endfopen
			} //end file_exist
		}
		else
		{
			if($FB) $FB->info('CSV-Daten aufbereiten in PHP-Version >= 5.3');

			if(file_exists($file) || is_readable($file))
			{
				$csvArray = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

				if($FB) $FB->log($csvArray, '$csvArray');

				foreach($csvArray as &$row)
				{
					$row = str_getcsv($row, $this->delimiter, "$this->enclosure");

					array_walk($row,
							function (&$entry) {
								$enc = mb_detect_encoding($entry, "UTF-8,ISO-8859-1,WINDOWS-1252");
								$entry = ($enc == 'UTF-8') ? trim($entry) : trim(iconv($enc, 'UTF-8', $entry));
							}
					);
				} //endforeach

				$csv['datas'] = $csvArray;
				$return = true;

				if($FB) $FB->log($csv['datas'], '$csv["datas"]');
			} //end file_exist
		} //endif phpversion

		if($FB) {
			$FB->log($return, '$return');

			if($return) {
				$FB->info(__FUNCTION__ . '() => wurde ordnungsgemäß ausgeführt.');
			}
			else {
				$FB->warn(__FUNCTION__ . '() => Datei konnte nicht gefunden werden, oder war nicht lesbar.');
			}

			$FB->groupEnd();
		}

		return $return;
	}

	protected function _buildHtml()
	{
		$FB = $this->FB;
		if($FB) $FB->group(__FUNCTION__.'()', array('Collapsed' => true, 'Color' => '#6699CC'));

		$output = '<div class="jtcsv2html_wrapper">';

		if($this->params->get('search',1))
		{
			$output .= '<input type="text" class="search" placeholder="Type to search">';
			JHtml::_('jquery.framework');
			JHtml::script('/plugins/content/jtcsv2html/assets/plg_jtcsv2html.js', false, false);
		}

		ob_start();
		require $this->_csv['tplPath'];

		$output .= ob_get_clean();

		$output .= '</div>';

		if($FB) $FB->log($output, '$output');

		if(!class_exists('Minify_HTML')) {
			require_once 'assets/minifyHTML.inc';
		}
		$this->_html = Minify_HTML::minify($output);

		if($FB) {
			$FB->info(__FUNCTION__.'() => wurde ordnungsgemäß ausgeführt');
			$FB->groupEnd();
		}
	}

	protected function _dbClearAll()
	{
		$FB = $this->FB;
		$db = JFactory::getDBO();
		$query = $db->getQuery(true);

		// Zuruecksetzen des Parameters
		$this->params->set('clearDB', 0);
		$params = $this->params->toString();
		$plgName = $this->_name;

		if($FB) {
			$FB->log($plgName, '$plgName');
			$FB->log($params, '$params');
		}

		$query->clear();
		$query->update('#__extensions');
		$query->set('params=' . $db->quote($params));
		$query->where('name=' . $db->quote('PLG_CONTENT_JTCSV2HTML'));

		$db->setQuery($query);
		$q1 = $db->execute();

		// Loeschen aller Daten aus der Datenbank
		$query->clear();
		$db->setQuery('TRUNCATE #__jtcsv2html');
		$q2 = $db->execute();

		$query->clear();
		$db->setQuery('OPTIMIZE TABLE #__jtcsv2html');
		if($FB) $FB->info((string)$db->getQuery(), '$db->getQuery()');
		$q3 = $db->execute();

		if($FB) {
			$FB->log($q1, 'Plugin-Einstellung zurückgesetzt');
			$FB->log($q2, 'Datenbank bereinigt');
			$FB->log($q3, 'Datenbank optimiert');
		}
	}
}
