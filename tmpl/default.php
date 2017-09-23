<?php
/**
 * @Copyright    JoomTools
 * @package      JT - Csv2Html - Plugin for Joomla! 2.5.x - 3.x
 * @author       Guido De Gobbis
 * @link         http://www.joomtools.de
 *
 * @license      GNU/GPL <http://www.gnu.org/licenses/>
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU General Public License as published by
 *              the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 */


// no direct access
defined('_JEXEC') or die;
?>
<table class="<?php echo $this->_csv['filename']; ?> <?php echo $this->_csv['tplname']; ?>">
	<thead>
	<tr>
		<?php // erstellen der Kopfzeile
		foreach ($this->_csv['datas'][0] as $hKey => $headline) : ?>
			<th class="csvcol<?php echo($hKey + 1); ?>"><?php echo $headline; ?></th>
		<?php endforeach; ?>
	</tr>
	</thead>
	<tbody>
	<?php // erstellen des Tabelleninhalts
	if (array_key_exists('1', $this->_csv['datas']) === false)
	{
		$this->_csv['datas'][0] = array(false);
	}

	// erstellen der Datenzeilen
	foreach ($this->_csv['datas'] as $dKey => $datas) :
		if ($dKey == 0)
		{
			$datasCount = count($datas);
			continue;
		}
		?>
		<tr>
			<?php
			for ($i = 0; $i < $datasCount; $i++) :
				if ($datas[$i] === false) : ?>
					<td style="text-align: center;" colspan="<?php echo $datasCount; ?>">
						<?php echo JText::_('PLG_CONTENT_JTCSV2HTML_CSVEMPTY'); ?>
					</td>
					<?php
					$i = $datasCount;
					continue;
				endif;
				$datas[$i] = ($datas[$i] == '') ? '&nbsp;' : $datas[$i];
				?>
				<td class="csvcol<?php echo($i + 1); ?>"><?php echo $datas[$i]; ?></td>
			<?php endfor; ?>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
