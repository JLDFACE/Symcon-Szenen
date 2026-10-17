# FACE SzenenEditor

IP-Symcon-Modul: kundenseitig editierbare Szenen über eine lokale Web-Oberfläche (WebHook).

- Jede Szene ist ein **Ablaufplan** (Wert-Aktionen „Auf Wert schalten“ / „Auf Prozentwert dimmen“).
- Der Kunde bearbeitet die Werte unter `http://<SymBox>:3777/hook/<HookName>` (optional PIN per Basic-Auth),
  kann live testen und speichert direkt in den Ablaufplan. Einzelne Ziele lassen sich aus einer Szene nehmen.
- Abruf aus Skripten: `FSE_CallScene($InstanzID, 'Szenenname');`
- Ist-Zustand als Szene speichern: `FSE_SaveCurrent($InstanzID, 'Szenenname');`

Konfiguration der Instanz: HookName, PIN, Liste „Szene → Ablaufplan“.

## Szenen je Raum und Raumteilung

Heißen alle Szenen `Szene · Teil` (z. B. `Präsentation 1 · Raum 2`), zeigt der Editor zwei Reihen:
oben die Szene, darunter den Teil (Raum).

Optional beschreibt die Eigenschaft **Partitions** (JSON) eine Raumteilung mit Trennwänden:

```json
{
  "part": "Trennwände",
  "openValue": true,
  "rooms": [
    { "name": "Raum 1", "col": 0, "row": 0 },
    { "name": "Raum 2", "col": 0, "row": 1 },
    { "name": "Raum 3", "col": 1, "row": 0 },
    { "name": "Raum 4", "col": 1, "row": 1 }
  ],
  "walls": [
    { "variableId": 12345, "rooms": ["Raum 1", "Raum 2"] },
    { "variableId": 12346, "rooms": ["Raum 1", "Raum 3"] }
  ]
}
```

- `part`: Name-Teil der Trennwand-Szenen (`Präsentation 1 · Trennwände`). Dieser Teil steht in der Reihe ganz vorn
  und wird als Grundriss gezeigt (`col`/`row` = Lage im Raster). Eine Wand antippen schaltet offen/geschlossen.
- `walls[].variableId`: Wand-Variable, die der Trennwand-Plan setzt; `openValue` = Wert für „offen“.
- Räume, die in einer Szene über offene Wände gekoppelt sind, werden ausgeblendet; es bleibt der führende Raum
  (der erste in `rooms`), beschriftet z. B. „Raum 1 + 2“.
- Schalter „Trennwände mit dieser Szene setzen“ aus: Die Wand-Aktionen werden aus dem Plan genommen (geparkt),
  die Szene lässt die Wände unverändert.

## Ziele aus einer Szene nehmen

Jede Ziel-Zeile hat einen Haken: ohne Haken wird die Aktion beim Speichern aus dem Ablaufplan genommen (geparkt)
und die Szene verändert das Ziel nicht. Der Schalter „… in dieser Szene schalten“ oben nimmt alle Ziele eines Teils
auf einmal heraus bzw. wieder hinein (z. B. „WC“ in einer Szene „Alles aus“). Geparkte Ziele bleiben sichtbar und
lassen sich jederzeit zurückholen.

Beschriftung der Ziele im Editor: die **Objekt-Info** der Variable, sonst ihr Name (bei „Value“ der Name des Geräts).

## Speichermodus (SKUZ-Prinzip)

Der Kunde stellt das Licht in der Visu ein und speichert den Ist-Zustand in eine Szene – ohne Webseite.

- Haken **„Speichermodus für die Visu“** legt zwei Variablen an:
  - **Szene** (Integer, Profil `FSE.Scenes.<InstanzID>`, Werte 1…n in der Reihenfolge der Szenenliste). Antippen ruft die
    Szene ab; die Variable zeigt die zuletzt abgerufene bzw. gespeicherte Szene (Rückmeldung für ValueButtons).
  - **Speichermodus** (Bool). Ist er an, *speichert* ein Antippen der Szene den Ist-Zustand, statt sie abzurufen.
    Danach geht er sofort aus, sonst nach „Speichermodus schaltet sich ab nach“ Sekunden (0 = nie).
- In IPSView: Speichermodus als Gedrückthalten-Taste (longpressaction 31 auf die Variable, LONGPRESSVALUE `true`),
  daneben eine gesperrte Anzeige; die Szenentasten als ValueButtons auf **Szene**.
- Gespeichert werden die Ziele, die im Ablaufplan stehen; geparkte Ziele bleiben geparkt. Dimmer werden in Prozent
  des Variablenprofils umgerechnet.
- Im Web-Editor holt **„Ist-Zustand“** die aktuellen Werte in die Regler; „Speichern“ sichert sie.

**Achtung:** Gespeichert wird, was Symcon kennt. KNX-Dimmer ohne Rückmeldung zeigen den zuletzt *gesendeten* Wert –
wer am Wandtaster dimmt, landet nicht in der Szene.

Erkannte Ablaufplan-Aktionen: „Auf Wert schalten“, „Auf Prozentwert dimmen“ und „Schalten“ (Bool mit Profil).
