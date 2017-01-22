# JT - Csv2Html
Content-Plugin für [Joomla!&trade;](https://joomla.org) um CSV-Dateien als Tabelle in einem Beitrag darzustellen.

## Aufruf
```php
{jtcsv2html string $filename[,string $templatename = 'default'[, string $filter]]}
```

+ **$filename:**  
Dateiname der CSV ohne Endung  
**_testfile_** = images/jtcsv2html/testfile.csv

+ **$templatename:**  
Dieses Plugin bringt zwei Vorlagen mit:  
**_default_**: eine einfache Tabelle  
**_responsive_**: eine responsive Tabelle  
**_eigene_**: eine eigene Vorlage kann in einem der jeweiligen [Override-Ordner](#overrides) erstellt werden

+ **$filter:**  
Der Filter wird global in den Plugin-Einstellungen konfiguriert und kann an dieser Stelle überschrieben werden.  
**_on_**: aktiviert den Filter  
**_off_**: deaktiviert den Filter

### Overrides
Reihenfolge, in der nach Overrides von Ausgabe und CSS gesucht wird. Es wir jeweils die erste Übereinstimmung genommen.

+ **Plugin-Ausgabe:**  
```php
// Templatespezifische Ausgabe
- [templates/YOUR_TEMPLATE/html/plg_content_jtcsv2html/{$templatemname}.php]

// Templateübergreifende Ausgabe
- [images/jtcsv2html/{$templatemname}.php]

// Standardausgabe
- [plugins/content/jtcsv2html/tmpl/{$templatename}.php

// Fallback - templatespezifische Ausgabe
- [templates/YOUR_TEMPLATE/html/plg_content_jtcsv2html/default.php]

// Fallback - templateübergreifende Ausgabe
- [images/jtcsv2html/default.php]

// Fallback - Standardausgabe
- [plugins/content/jtcsv2html/tmpl/default.php
```

+ **CSS:**  
```php
// Templatespezifische Formatierung für nur diese Datei
- [templates/YOUR_TEMPLATE/html/plg_content_jtcsv2html/{$filename}.css]

// Templateübergreifende Formatierung für nur diese Datei
- [images/jtcsv2html/{$filename}.php]

// Templatespezifische Formatierung für die Ausgabevorlage
- [templates/YOUR_TEMPLATE/html/plg_content_jtcsv2html/{$templatemname}.css]

// Templateübergreifende Formatierung für die Ausgabevorlage
- [images/jtcsv2html/{$templatemname}.css]

// Standardformatierung für die Ausgabevorlage
- [plugins/content/jtcsv2html/tmpl/{$templatename}.css

// Fallback - templatespezifische Formatierung für die Ausgabevorlage
- [templates/YOUR_TEMPLATE/html/plg_content_jtcsv2html/default.css]

// Fallback - templateübergreifende Formatierung für die Ausgabevorlage
- [images/jtcsv2html/default.css]

// Fallback - Standardformatierung für die Ausgabevorlage
- [plugins/content/jtcsv2html/tmpl/default.css
```

### Danke für die Unterstützung
[@astridx](https://github.com/astridx) - für die Implementierung der Filterfunktion


## Plugin für die Suche
(https://github.com/JoomTools/plg_search_jtcsv2html/tree/dev)





