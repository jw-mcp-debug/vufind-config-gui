# vufind-config-gui

Eine schlanke Weboberfläche für die lokalen Konfigurationsdateien von
[VuFind®](https://vufind.org). Sie ist für Bibliothekarinnen, Bibliothekare und
Entwickler gedacht, die eine VuFind-Installation verstehen und einstellen
wollen, ohne ini-Dateien von Hand zu bearbeiten.

[English version](README.md)

> **Stand: Beta.** Ein unabhängiges Projekt, kein Teil von VuFind und nicht von
> der VuFind-Community geprüft. Bitte zuerst an einer Testinstallation
> ausprobieren und vor jedem Betrieb außerhalb des eigenen Rechners
> [SECURITY.md](SECURITY.md) lesen.

## Funktionen

- **ini- und properties-Dateien strukturiert bearbeiten:** Alle Optionen der
  Originaldatei mit Hilfetext. Optionen lassen sich ein- und auskommentieren,
  Werte ändern und auf den Standard zurücksetzen. Kommentare, auskommentierte
  Optionen und Formatierung bleiben erhalten. Geändert werden nur die
  bearbeiteten Zeilen.
- **Originale bleiben unberührt:** Gelesen wird aus `VUFIND_HOME`, geschrieben
  nur nach `VUFIND_LOCAL_DIR`. Beim ersten Speichern entsteht eine lokale
  Kopie. Vor jedem Schreiben wird eine Sicherung angelegt, danach leert die GUI
  VuFinds Konfigurations-Caches. Wurde die Datei inzwischen anderweitig
  geändert, verweigert sie das Speichern.
- **Prüfung vor dem Speichern:** Eine ini-Datei, die PHP und damit auch VuFind
  nicht lesen könnte, wird nie geschrieben.
- **Rohtext-Editor und Diff** zum Original, auch für YAML.
- **Globale Suche** über alle Einstellungen, Werte und Hilfetexte (Taste
  <kbd>/</kbd>).
- **Ranking-Editor** für `searchspecs.yaml`: Feldgewichte je Suchtyp,
  Dismax-Parameter, Phrasensuche und `GlobalExtraParams`. Die Vorschau schickt
  dieselbe Anfrage wie VuFind direkt an Solr, einmal mit dem gespeicherten
  Stand und einmal mit dem Entwurf, und zeigt, wie sich jeder Treffer
  verschiebt.
- **Mehrere VuFind-Instanzen** in einer Oberfläche, etwa Test und Produktion.
- **Deutsch und Englisch**, oben umschaltbar.
- **Optional und experimentell: MCP-Editor und MCP-Test** für den
  MCP-Server aus [vufind-org/vufind#4939](https://github.com/vufind-org/vufind/pull/4939)
  (noch nicht gemergt).

## Schnellstart

```bash
git clone https://github.com/jw-mcp-debug/vufind-config-gui.git
cd vufind-config-gui
cp config/config.example.php config/config.php   # home, local, url, solr eintragen
php -S 127.0.0.1:8181 -t public
```

Dann <http://127.0.0.1:8181/> öffnen. Voraussetzungen, Konfiguration, Docker,
Tests und bekannte Grenzen stehen ausführlich in der
[englischen README](README.md).

## Sicherheit in Kürze

Die GUI kann jede VuFind-Einstellung ändern und zeigt Passwörter aus der
Konfiguration an. Sie gehört deshalb nur auf `127.0.0.1` (Zugriff per
SSH-Tunnel) oder hinter einen Reverse-Proxy mit Anmeldung. Von sich aus schützt
sie gegen Cross-Site-Requests, DNS-Rebinding und Clickjacking. Einzelheiten
stehen in [SECURITY.md](SECURITY.md).

## Lizenz

GNU General Public License, nur Version 2 (GPL-2.0-only), wie VuFind.

VuFind® ist eine eingetragene Marke der Villanova University. Dieses Projekt
steht in keiner Verbindung zur Villanova University oder zum VuFind-Projekt.
