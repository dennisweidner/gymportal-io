# Agent API für gymportal

Eine Schnittstelle, über die KI-Agenten und Automatisierungen für **ein Studio** auf gymportal
zugreifen: Fragen zu Mitgliedern, Verträgen, Zutritt, Zahlungen und Auslastung beantworten,
und auf Wunsch schreiben (Kontaktdaten ändern, kündigen, Kündigung zurücknehmen, pausieren,
fortsetzen, Notiz anlegen).

Aufsatz auf [mariokehl/gymportal-io](https://github.com/mariokehl/gymportal-io), Stand `develop`
v0.0.156. Lizenz wie das Projekt: GPL-3.0.

## Warum es das braucht

gymportal hat Schnittstellen für die Mitglieder-App (`/api/pwa`), das Anmelde-Widget
(`/api/widget`), den Gäste-Shop (`/api/guests`) und die Scanner (`/api/scanner`). Für den
Betreiber selbst gibt es nur die Oberfläche im Browser. Ein Agent, der dem Betreiber Arbeit
abnimmt, hat damit keinen Zugang. Diese API schließt die Lücke, ohne Fachlogik zu kopieren:
Kündigungstermine, Fristen und Pausenregeln kommen aus denselben Modellen und Diensten, die
Portal und Mitglieder-App benutzen.

## Drei Zusagen

1. **Das Studio kommt aus dem Token.** Ein Token gehört zu genau einem Studio. Nichts im
   Aufruf kann ein anderes Studio wählen; ein Mitglied eines anderen Studios existiert für
   das Token nicht (404).
2. **Ein Token kann nur, was seine Rechte sagen.** Lesen und Schreiben sind getrennt. Ein
   Agent, der nur Fragen beantwortet, bekommt kein Schreibrecht. Hat das Studio weder
   Testphase noch Abo, antwortet die API wie das Portal nicht mehr (403 `gym_without_access`).
3. **Schreiben ist zweistufig.** Ohne `"confirm": true` liefert jeder schreibende Aufruf nur
   die Vorschau (`"preview": true`) und ändert nichts. So kann der Agent dem Menschen den
   genauen Effekt zeigen, bevor etwas gespeichert wird. `confirm` zählt nur im JSON-Körper,
   nie in der Adresse. Eine Kündigung nennt beim Bestätigen das Datum der Vorschau
   (`expected_effective_date`); hat es sich verschoben, wird nichts gespeichert (409).

Dazu ein Protokoll: jede Anfrage landet in `agent_api_logs` (wer, was, welches Mitglied,
Statuscode, ob Daten geändert wurden). Bei einem ausgeführten Schreibvorgang stehen alter und
neuer Wert dabei, sonst keine Inhalte.

## Betrieb ohne den Portal-Anbieter: der Spiegel

Wer gymportal als gehosteten Dienst nutzt, kann diese API nicht selbst dort einspielen. Dafür
gibt es den Spiegel: eine eigene Installation dieses Codes, die ihre Daten aus dem Studio-Export
bekommt, den das Portal ohnehin anbietet (Einstellungen, Datentransfer, Export als JSON).

```bash
# einmalig: Studio aus dem ersten Export anlegen, Token ausstellen
php artisan agent:mirror import gym_export_studio_2026-11-12.json --create
php artisan agent:token create --gym=<slug> --name="Assistent" --purpose="..." --abilities=all

# danach bei jedem neuen Export
php artisan agent:mirror import <datei.json>          # auf dem Server
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     --data-binary @<datei.json> "$URL/api/v1/agent/mirror/import"   # von überall
# oder im Browser: $URL/agent-api/upload.html
```

Was im Spiegel gilt:

- **Lesen** funktioniert wie im Live-Betrieb, auf dem Stand des letzten Exports. `GET /health`
  und `GET /mirror` nennen `mode: mirror` und das Alter der Daten.
- **Schreiben** ändert den Spiegel nie, denn der nächste Import würde es still zurückdrehen.
  Ein bestätigter Schreibaufruf wird zum **Auftrag** (`202`, `queued: true`): was zu tun ist,
  für welches Mitglied, mit Link ins Portal (`AGENT_API_PORTAL_URL`). Ein Mensch trägt es im
  Portal ein.
- **Abgleich:** Der nächste Import schließt jeden Auftrag, dessen Wirkung der neue Export zeigt
  (Kündigung eingetragen, Kontaktdaten geändert, Pause gesetzt, Notiz vorhanden). Was offen
  bleibt, steht weiter in `GET /actions`.
- **Grenzen des Exports:** Die Zutrittseinstellung (QR, NFC) und Zahlungsarten stehen nicht im
  Export. `GET /members/{id}/access` sagt das (`access_medium_known: false`) und erfindet
  keinen Grund. Mitglieder bekommen bei jedem Import neue IDs; stabil ist die Mitgliedsnummer.
- **Schutz:** Ein Import in ein Studio mit eigenen Mitgliedern, das kein Spiegel ist, wird
  abgelehnt.

Der Spiegel braucht PHP 8.4 und eine Datenbank (MySQL oder SQLite), sonst nichts vom Portal:
keine Zahlungsanbindung, kein Mailversand, keine Scanner.

## Endpunkte

Basis: `/api/v1/agent`, Anmeldung per `Authorization: Bearer <Token>`. Vollständig in
[`openapi.yaml`](openapi.yaml).

| Methode | Pfad | Recht | Wozu |
| --- | --- | --- | --- |
| GET | `/health` | – | Studio und Rechte des Tokens |
| GET | `/plans` | `plans.read` | Tarife, Preise, Laufzeit, Kündigungsfrist |
| GET | `/members` | `members.read` | Suche nach Name, E-Mail, Mitgliedsnummer |
| GET | `/members/lookup` | `members.read` | genau ein Mitglied per E-Mail, Nummer oder Telefon |
| GET | `/members/{id}` | `members.read` | Stammdaten |
| GET | `/members/{id}/contract` | `contracts.read` | Vertrag, nächster Kündigungstermin, Pause, Widerruf; im Gratis-Zeitraum zusätzlich der bezahlte Vertrag (`contract_membership`) |
| GET | `/members/{id}/access` | `access.read` | sollte der Zutritt gehen, und wenn nicht, warum |
| GET | `/members/{id}/check-ins` | `access.read` | letzte Besuche |
| GET | `/members/{id}/payments` | `payments.read` | Zahlungen, offene und fehlgeschlagene |
| GET | `/occupancy` | `stats.read` | Besuche je Wochentag und Stunde |
| GET | `/stats` | `stats.read` | Kennzahlen des Studios (jede Umsatzzahl nur mit `payments.read`) |
| GET | `/insights/inactive-members` | `stats.read` | laufender Vertrag, aber seit N Tagen kein Besuch |
| GET | `/mirror` | – | Spiegel: Stand und Alter der Daten, offene Aufträge |
| POST | `/mirror/import` | `mirror.write` | Studio-Export einspielen |
| GET | `/actions` | `actions.read` | Aufträge, die im Portal auszuführen sind |
| POST | `/actions/{id}/close` | `actions.write` | Auftrag als erledigt oder verworfen markieren |
| PATCH | `/members/{id}` | `members.write` | Kontaktdaten ändern (ohne E-Mail, die ist der Login der Mitglieder-App) |
| POST | `/members/{id}/notes` | `members.write` | Notiz am Mitglied anlegen |
| POST | `/members/{id}/contract/cancellation` | `contracts.write` | ordentlich kündigen zum nächstmöglichen Termin |
| DELETE | `/members/{id}/contract/cancellation` | `contracts.write` | Kündigung zurücknehmen |
| POST | `/members/{id}/contract/pause` | `contracts.write` | pausieren von/bis |
| POST | `/members/{id}/contract/resume` | `contracts.write` | Pause beenden |

Antworten haben immer dieselbe Hülle: `{"success": true, "data": {...}}` oder
`{"success": false, "error": {"code": "...", "message": "..."}}`.

## Token ausstellen

```bash
php artisan agent:token create --gym=<id oder slug> --name="Assistent" \
    --purpose="Beantwortet Mitgliederfragen" --abilities=read
php artisan agent:token list --gym=<id oder slug>
php artisan agent:token revoke --id=<id>
```

`--abilities` nimmt `read`, `all` oder eine Liste wie `members.read,contracts.read,contracts.write`.
Das Token wird einmal angezeigt und nur als Hash gespeichert. `--expires=2027-01-31` setzt ein
Ablaufdatum. Der Zweck ist Pflicht, weil er ins Verzeichnis der Verarbeitungstätigkeiten gehört.

## Beispiel

```bash
curl -H "Authorization: Bearer $TOKEN" "$URL/api/v1/agent/members/lookup?email=mia@example.org"
curl -H "Authorization: Bearer $TOKEN" "$URL/api/v1/agent/members/7/contract"

# Vorschau: ändert nichts
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"reason":"move"}' "$URL/api/v1/agent/members/7/contract/cancellation"

# Ausführen
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"reason":"move","confirm":true,"expected_effective_date":"2027-01-31","send_confirmation":true}' \
     "$URL/api/v1/agent/members/7/contract/cancellation"
```

## Für KI-Agenten: MCP-Server

[`mcp/server.mjs`](mcp/server.mjs) stellt die API als 21 Werkzeuge über das Model Context
Protocol bereit (Node ab 18, keine Abhängigkeiten). Damit kann jeder MCP-fähige Agent
(Desktop-Assistenten, n8n, eigene Agenten) direkt damit arbeiten:

```json
{
  "mcpServers": {
    "gymportal": {
      "command": "node",
      "args": ["agent-api/mcp/server.mjs"],
      "env": { "GYMPORTAL_API_URL": "https://<portal>", "GYMPORTAL_API_TOKEN": "gpa_..." }
    }
  }
}
```

Die Schreibwerkzeuge reichen `confirm` durch; ohne `confirm: true` bleibt es bei der Vorschau.

## Einbau in gymportal

Neue Dateien: `routes/agent.php`, `app/Http/Controllers/Api/Agent/*`,
`app/Http/Middleware/AuthenticateAgent.php`, `app/Models/GymApiClient.php`,
`app/Models/AgentApiLog.php`, `app/Models/AgentMirrorSync.php`, `app/Models/AgentPendingAction.php`,
`app/Services/AgentApi/MirrorService.php`, `app/Console/Commands/AgentTokenCommand.php`,
`app/Console/Commands/AgentMirrorCommand.php`, `public/agent-api/upload.html`, zwei Migrationen
(vier neue Tabellen, keine Änderung an bestehenden), `tests/Feature/Api/Agent/*`,
`database/seeders/AgentApiDemoSeeder.php`.

Geändert sind nur drei bestehende Dateien, je um wenige Zeilen: `routes/api.php` lädt
`agent.php` im bestehenden `v1`-Block (Präfix `agent`), `bootstrap/app.php` kennt den Alias
`agent.auth` und nimmt `api/v1/agent/*` von der CSRF-Prüfung aus, `config/services.php` kennt
`agent_api.portal_url`.

```bash
php artisan migrate
php artisan test tests/Feature/Api/Agent
php artisan db:seed --class=AgentApiDemoSeeder   # erfundene Daten zum Ausprobieren
```

## Tests

40 Tests in `tests/Feature/Api/Agent/` (31 zur API, 9 zum Spiegel) gegen die echte Fachlogik (SQLite im
Speicher wie der Rest der Suite): Anmeldung, widerrufene und abgelaufene Tokens, Rechte,
Mandantentrennung, Vertragsdaten, Zutritt, Auslastung, Kennzahlen, alle Schreibwege mit
Vorschau und Ausführung, Protokoll, Kommandozeile,
dazu ein Test, der `openapi.yaml` und den MCP-Server gegen die echten Routen hält.
