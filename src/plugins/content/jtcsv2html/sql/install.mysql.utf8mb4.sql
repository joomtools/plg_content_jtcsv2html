--
-- Tabellenstruktur für Tabelle `#__jtcsv2html`
--

CREATE TABLE IF NOT EXISTS `#__jtcsv2html` (
  `filename` varchar(191) NOT NULL COMMENT 'CSV-Dateiname',
  `tpl` varchar(191) NOT NULL COMMENT 'Ausgabetemplate',
  `filetime` varchar(255) NOT NULL COMMENT 'Dateidatum im UNIX-Format',
  `datas` longtext NOT NULL COMMENT 'Ausgabe der CSV-Datei als Tabelle (Template-HTML)',
  PRIMARY KEY (`filename`, `tpl`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci COMMENT='Tabelle das Plugins jtcsv2html';

--
-- Tabellenstruktur für Tabelle `#__jtcsv2html_assoc`
--

CREATE TABLE IF NOT EXISTS `#__jtcsv2html_assoc` (
  `cid` INT NOT NULL COMMENT 'Content-ID',
  `filename` varchar(191) NOT NULL COMMENT 'CSV-Dateiname',
  PRIMARY KEY (`cid`, `filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci COMMENT='Tabelle das Plugins jtcsv2html';
