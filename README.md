# FACE SzenenEditor

IP-Symcon-Modul: kundenseitig editierbare Szenen über eine lokale Web-Oberfläche (WebHook).

- Jede Szene ist ein **Ablaufplan** (Wert-Aktionen „Auf Wert schalten“ / „Auf Prozentwert dimmen“).
- Der Kunde bearbeitet die Werte unter `http://<SymBox>:3777/hook/<HookName>` (optional PIN per Basic-Auth),
  kann live testen und speichert direkt in den Ablaufplan. Einzelne Ziele lassen sich aus einer Szene nehmen.
- Abruf aus Skripten: `FSE_CallScene($InstanzID, 'Szenenname');`

Konfiguration der Instanz: HookName, PIN, Liste „Szene → Ablaufplan“.
