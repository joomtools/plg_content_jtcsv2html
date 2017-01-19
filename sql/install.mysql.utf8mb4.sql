--
-- Tabellenstruktur für Tabelle `#__csv2html`
--

CREATE TABLE IF NOT EXISTS `#__jtcsv2html` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cid` int(10) NOT NULL COMMENT 'Content-ID',
  `filename` varchar(255) NOT NULL COMMENT 'Dateiname ohne Endung',
  `tplname` varchar(255) NOT NULL COMMENT 'Templatename ohne Endung',
  `filetime` varchar(255) NOT NULL COMMENT 'Dateidatum im UNIX-Format',
  `datas` longtext NOT NULL COMMENT 'Ausgabe der CSV-Datei als Tabelle (Template-HTML)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci COMMENT='Tabelle das Plugins jtcsv2html' AUTO_INCREMENT=0;
