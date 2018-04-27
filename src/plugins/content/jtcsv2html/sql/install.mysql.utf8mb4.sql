--
-- Tabellenstruktur für Tabelle `#__jtcsv2html`
--

CREATE TABLE IF NOT EXISTS `#__jtcsv2html` (
  `path` varchar(191) NOT NULL COMMENT 'CSV-Aufruf',
  `filetime` varchar(255) NOT NULL COMMENT 'Dateidatum im UNIX-Format',
  `datas` longtext NOT NULL COMMENT 'Ausgabe der CSV-Datei als Tabelle (Template-HTML)',
  PRIMARY KEY (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci COMMENT='Tabelle das Plugins jtcsv2html';

--
-- Tabellenstruktur für Tabelle `#__jtcsv2html_assoc`
--

CREATE TABLE IF NOT EXISTS `#__jtcsv2html_assoc` (
  `cid` INT NOT NULL COMMENT 'Content-ID',
  `path` varchar(191) NOT NULL COMMENT 'CSV-Aufruf',
  PRIMARY KEY (`cid`, `path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci COMMENT='Tabelle das Plugins jtcsv2html';
