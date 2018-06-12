<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Content.Jtcsv2html
 *
 * @author       Guido De Gobbis <support@joomtools.de>
 * @copyright    (c) 2018 JoomTools.de - All rights reserved.
 * @license      GNU General Public License version 3 or later
 */

// no direct access
defined('_JEXEC') or die;
extract($displayData);
?>
<table class="<?php echo $csv['fileName']; ?> <?php echo $csv['tplName']; ?>">
	<thead>
	<tr>
		<?php // erstellen der Kopfzeile
		foreach ($csv['content'][0] as $hKey => $headline) :
			$data_th[$hKey] = $headline;
			?>
			<th class="csvcol<?php echo($hKey + 1); ?>"><?php echo $headline; ?></th>
		<?php endforeach; ?>
	</tr>
	</thead>
	<tbody>
	<?php // erstellen des Tabelleninhalts
	if (array_key_exists('1', $csv['content']) === false)
	{
		$csv['content'][0] = array(false);
	}

	// erstellen der Datenzeilen
	foreach ($csv['content'] as $dKey => $datas) :
		if ($dKey == 0)
		{
			$datasCount = count($datas);
			continue;
		}
		?>
		<tr class="jtcsv2html-item">
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
				<td data-th="<?php echo $data_th[$i]; ?>"
				    class="csvcol<?php echo($i + 1); ?>"><?php echo $datas[$i]; ?></td>
			<?php endfor; ?>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
