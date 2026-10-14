<?php

declare(strict_types=1);

class SzenenEditor extends IPSModule
{
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Ablaufplan-Aktionen, die reine Wert-Zuweisungen sind
    private const ACTION_SET_VALUE = '{46B65CA6-3098-4982-9A6C-B89DADBB0A96}'; // "Auf Wert schalten" (VALUE)
    private const ACTION_DIM       = '{F25466DC-917D-4D81-979F-82494B559340}'; // "Auf Prozentwert dimmen" (PERCENT_VALUE)

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('HookName', 'szenen');
        $this->RegisterPropertyString('PIN', '');

        // Szenen: [{ "Scene": "Kochen", "PlanID": 55725 }, ...] — PlanID ist ein Ablaufplan (ScriptType 1)
        $this->RegisterPropertyString('Plans', '[]');

        // Vom Kunden aus der Szene genommene Aktionen: { "<Szene>": { "<varID>": <action-json> } }
        $this->RegisterAttributeString('DisabledActions', '{}');
    }

    public function Destroy()
    {
        // Instanz wird gelöscht -> Hook austragen (per TargetID, Properties sind hier ggf. weg)
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->UnregisterHookByTarget();
        }
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->RegisterHook('/hook/' . $this->ReadPropertyString('HookName'));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->RegisterHook('/hook/' . $this->ReadPropertyString('HookName'));
        }
    }

    /**
     * Szene abrufen: führt den hinterlegten Ablaufplan aus (inkl. Kundenwerten).
     * FSE_CallScene(12345, 'Kochen');
     */
    public function CallScene(string $SceneName): bool
    {
        $planID = $this->GetPlanID($SceneName);
        if ($planID === 0) {
            $this->SendDebug('CallScene', 'Kein Ablaufplan für ' . $SceneName, 0);
            return false;
        }
        return IPS_RunScript($planID);
    }

    protected function ProcessHookData()
    {
        $base  = '/hook/' . $this->ReadPropertyString('HookName');
        $path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $route = trim(substr($path, strlen($base)), '/');
        $method = $_SERVER['REQUEST_METHOD'];

        if (!$this->CheckAuth()) {
            header('WWW-Authenticate: Basic realm="Szenen-Editor"');
            http_response_code(401);
            echo 'Anmeldung erforderlich';
            return;
        }

        if ($route === '' && $method === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            readfile(__DIR__ . '/webui/index.html');
            return;
        }

        if ($route === 'api/config' && $method === 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->BuildConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($route === 'api/save' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            header('Content-Type: application/json; charset=utf-8');
            if (!is_array($body) || !isset($body['scene'], $body['values'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'scene/values fehlen']);
                return;
            }
            $err = $this->SaveToPlan(
                (string)$body['scene'],
                (array)$body['values'],
                array_map('intval', (array)($body['disabled'] ?? []))
            );
            echo json_encode($err === '' ? ['ok' => true] : ['ok' => false, 'error' => $err]);
            return;
        }

        if ($route === 'api/test' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            header('Content-Type: application/json; charset=utf-8');
            if (!is_array($body) || !isset($body['scene'], $body['values'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'scene/values fehlen']);
                return;
            }
            // Live anzeigen ohne zu speichern: nur Variablen, die im Plan der Szene vorkommen
            $allowed = $this->CollectPlanTargets($this->GetPlanID((string)$body['scene']));
            $ok = true;
            foreach ((array)$body['values'] as $varID => $value) {
                if (isset($allowed[(int)$varID])) {
                    $ok = $this->ApplyValue((int)$varID, $value) && $ok;
                }
            }
            echo json_encode(['ok' => $ok]);
            return;
        }

        if ($route === 'api/call' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $this->CallScene((string)($body['scene'] ?? ''))]);
            return;
        }

        http_response_code(404);
        echo 'Nicht gefunden';
    }

    // ---------------------------------------------------------------- intern

    private function CheckAuth(): bool
    {
        $pin = $this->ReadPropertyString('PIN');
        if ($pin === '') {
            return true;
        }
        // PIN niemals per SendDebug loggen!
        return isset($_SERVER['PHP_AUTH_PW']) && hash_equals($pin, $_SERVER['PHP_AUTH_PW']);
    }

    private function GetPlanID(string $scene): int
    {
        foreach (json_decode($this->ReadPropertyString('Plans'), true) as $row) {
            $name = $row['Scene'] !== '' ? $row['Scene'] : IPS_GetName((int)$row['PlanID']);
            if ($name === $scene && IPS_ScriptExists((int)$row['PlanID'])) {
                return (int)$row['PlanID'];
            }
        }
        return 0;
    }

    /**
     * Wert-Aktionen aus einem Ablaufplan einsammeln (rekursiv durch Bedingungen).
     * Liefert { varID: { count, value, actionID } } — nur existierende Variablen.
     */
    private function CollectPlanTargets(int $planID): array
    {
        if ($planID === 0 || !IPS_ScriptExists($planID)) {
            return [];
        }
        $plan = json_decode(IPS_GetScriptContent($planID), true);
        if (!is_array($plan) || !isset($plan['actions'])) {
            return [];
        }
        $out = [];
        $this->WalkActions($plan['actions'], function (array $a) use (&$out) {
            $info = $this->ValueActionInfo($a);
            if ($info === null || !IPS_VariableExists($info['target'])) {
                return;
            }
            $t = $info['target'];
            if (!isset($out[$t])) {
                $out[$t] = ['count' => 0, 'value' => $info['value'], 'actionID' => $a['actionID']];
            }
            $out[$t]['count']++;
        });
        return $out;
    }

    /** Rekursiv über alle Aktionen laufen (inkl. verschachtelter Bedingungs-Zweige). */
    private function WalkActions(array $actions, callable $cb): void
    {
        foreach ($actions as $a) {
            $cb($a);
            $p = $a['parameters'] ?? [];
            foreach (['ACTIONS', 'TRUE_ACTIONS', 'FALSE_ACTIONS', 'ELSE_ACTIONS'] as $k) {
                if (isset($p[$k]) && is_array($p[$k])) {
                    $this->WalkActions($p[$k], $cb);
                }
            }
        }
    }

    /** Wenn die Aktion eine reine Wert-Zuweisung ist: Ziel-Variable + Wert, sonst null. */
    private function ValueActionInfo(array $a): ?array
    {
        $p = $a['parameters'] ?? [];
        if ($a['actionID'] === self::ACTION_SET_VALUE && isset($p['TARGET'], $p['VALUE'])) {
            return ['target' => (int)$p['TARGET'], 'value' => $p['VALUE']];
        }
        if ($a['actionID'] === self::ACTION_DIM && isset($p['TARGET'], $p['PERCENT_VALUE'])) {
            return ['target' => (int)$p['TARGET'], 'value' => $p['PERCENT_VALUE']];
        }
        return null;
    }

    /**
     * Kundenwerte in den Ablaufplan zurückschreiben; abgewählte Ziele aus dem Plan
     * nehmen (Aktion wird im Attribut geparkt), wieder aktivierte zurücklegen.
     */
    private function SaveToPlan(string $scene, array $values, array $disabled): string
    {
        $planID = $this->GetPlanID($scene);
        if ($planID === 0) {
            return 'Szene hat keinen Ablaufplan';
        }
        $plan = json_decode(IPS_GetScriptContent($planID), true);
        if (!is_array($plan) || !isset($plan['actions'])) {
            return 'Ablaufplan nicht lesbar';
        }

        $targets  = $this->CollectPlanTargets($planID);
        $stashAll = json_decode($this->ReadAttributeString('DisabledActions'), true);
        $stash    = $stashAll[$scene] ?? [];
        $disabled = array_flip($disabled);

        // 1) Werte setzen / abgewählte Aktionen entfernen (nur eindeutige Ziele anfassen)
        $plan['actions'] = $this->RewriteActions($plan['actions'], $targets, $values, $disabled, $stash);

        // 2) Wieder aktivierte Ziele aus dem Parkplatz zurücklegen
        foreach ($stash as $varID => $action) {
            if (!isset($disabled[(int)$varID])) {
                if (isset($values[$varID])) { // neuen Wert direkt übernehmen
                    $action = $this->WithValue($action, $this->CastForVariable((int)$varID, $values[$varID]));
                }
                $plan['actions'][] = $action;
                unset($stash[$varID]);
            }
        }

        $stashAll[$scene] = $stash;
        $this->WriteAttributeString('DisabledActions', json_encode($stashAll));
        IPS_SetScriptContent($planID, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return '';
    }

    /** Aktionsbaum kopieren: Werte eindeutiger Ziele ersetzen, abgewählte entfernen (in $stash parken). */
    private function RewriteActions(array $actions, array $targets, array $values, array $disabled, array &$stash): array
    {
        $out = [];
        foreach ($actions as $a) {
            $info = $this->ValueActionInfo($a);
            $unique = $info !== null && isset($targets[$info['target']]) && $targets[$info['target']]['count'] === 1;

            if ($unique && isset($disabled[$info['target']])) {
                $stash[(string)$info['target']] = $a; // aus der Szene nehmen, aber aufheben
                continue;
            }
            if ($unique && array_key_exists((string)$info['target'], $values)) {
                $a = $this->WithValue($a, $this->CastForVariable($info['target'], $values[(string)$info['target']]));
            }
            $p = $a['parameters'] ?? [];
            foreach (['ACTIONS', 'TRUE_ACTIONS', 'FALSE_ACTIONS', 'ELSE_ACTIONS'] as $k) {
                if (isset($p[$k]) && is_array($p[$k])) {
                    $a['parameters'][$k] = $this->RewriteActions($p[$k], $targets, $values, $disabled, $stash);
                }
            }
            $out[] = $a;
        }
        return $out;
    }

    /** Neuen Wert in eine Wert-Aktion einsetzen (VALUE bzw. PERCENT_VALUE). */
    private function WithValue(array $action, $value): array
    {
        if ($action['actionID'] === self::ACTION_DIM) {
            $action['parameters']['PERCENT_VALUE'] = max(0, min(100, (int)$value));
        } else {
            $action['parameters']['VALUE'] = $value;
        }
        return $action;
    }

    /** Wert typrichtig zur Zielvariable casten. */
    private function CastForVariable(int $varID, $value)
    {
        if (!IPS_VariableExists($varID)) {
            return $value;
        }
        switch (IPS_GetVariable($varID)['VariableType']) {
            case VARIABLETYPE_BOOLEAN: return (bool)$value;
            case VARIABLETYPE_INTEGER: return (int)$value;
            case VARIABLETYPE_FLOAT:   return (float)$value;
            default:                   return (string)$value;
        }
    }

    /** Wert typrichtig per RequestAction rausschicken; nur senden, wenn abweichend. */
    private function ApplyValue(int $varID, $value): bool
    {
        if (!IPS_VariableExists($varID)) {
            $this->SendDebug('ApplyValue', 'Variable ' . $varID . ' existiert nicht', 0);
            return false;
        }
        $value = $this->CastForVariable($varID, $value);
        if (GetValue($varID) === $value) {
            return true; // SetValueIfChanged-Prinzip: keinen Bus-Traffic ohne Änderung
        }
        return @RequestAction($varID, $value) !== false;
    }

    /** Config fürs Frontend: Szenen mit Zielen aus den Ablaufplänen (+ geparkte, abgewählte Ziele). */
    private function BuildConfig(): array
    {
        $stashAll = json_decode($this->ReadAttributeString('DisabledActions'), true);
        $scenes = [];

        foreach (json_decode($this->ReadPropertyString('Plans'), true) as $row) {
            $planID = (int)$row['PlanID'];
            if (!IPS_ScriptExists($planID)) {
                continue;
            }
            $scene = $row['Scene'] !== '' ? $row['Scene'] : IPS_GetName($planID);
            $scenes[$scene] = [];

            foreach ($this->CollectPlanTargets($planID) as $varID => $info) {
                if ($info['count'] !== 1) {
                    continue; // mehrfach gesetzte Variablen = Ablauf-Logik, nicht anfassen
                }
                $scenes[$scene][] = $this->DescribeTarget($varID, $info['value'], $info['actionID'], true);
            }
            // Abgewählte (geparkte) Ziele mit anzeigen, damit der Kunde sie zurückholen kann
            foreach (($stashAll[$scene] ?? []) as $varID => $action) {
                $info = $this->ValueActionInfo($action);
                if ($info !== null && IPS_VariableExists((int)$varID)) {
                    $scenes[$scene][] = $this->DescribeTarget((int)$varID, $info['value'], $action['actionID'], false);
                }
            }
        }

        // Titel = Name der obersten Ordnerebene über der Instanz (Fallback: Instanzname)
        $topID = $this->InstanceID;
        while (($parentID = IPS_GetParent($topID)) > 0) {
            $topID = $parentID;
        }
        $title = $topID !== $this->InstanceID ? IPS_GetName($topID) : IPS_GetName($this->InstanceID);

        return ['title' => $title, 'scenes' => $scenes];
    }

    /** Ein Ziel fürs Frontend beschreiben (Label, Typ, Profilgrenzen). */
    private function DescribeTarget(int $varID, $value, string $actionID, bool $enabled): array
    {
        $var     = IPS_GetVariable($varID);
        $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

        // Label: Beschreibung der Variable (Objekt-Info), sonst ihr Name, bei generischem "Value" der des Geräts
        $label = trim(IPS_GetObject($varID)['ObjectInfo']);
        if ($label === '') {
            $label = IPS_GetName($varID);
            if (strcasecmp($label, 'Value') === 0) {
                $label = IPS_GetName(IPS_GetParent($varID));
            }
        }

        $target = [
            'variableId' => $varID,
            'label'      => $label,
            'varType'    => $var['VariableType'],
            'value'      => $value,
            'enabled'    => $enabled,
            'type'       => 'auto'
        ];
        if ($actionID === self::ACTION_DIM) {
            $target['type'] = 'dim';
            $target += ['min' => 0, 'max' => 100, 'step' => 1, 'suffix' => '%'];
        }
        if (stripos($profile, 'HexColor') !== false) {
            $target['type'] = 'color';
        }

        if ($target['type'] === 'auto' && $profile !== '' && IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            $target['min']    = $p['MinValue'];
            $target['max']    = $p['MaxValue'];
            $target['step']   = $p['StepSize'];
            $target['suffix'] = trim($p['Suffix']);
            if (count($p['Associations']) > 0) {
                $target['options'] = array_map(
                    fn($a) => ['value' => $a['Value'], 'name' => $a['Name']],
                    $p['Associations']
                );
            }
        }
        return $target;
    }

    private function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        $found = false;
        foreach ($hooks as $i => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ($hook['TargetID'] === $this->InstanceID) {
                    return; // schon korrekt registriert
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                $found = true;
            }
            // Alten Hook dieser Instanz entfernen (z. B. nach Umbenennung)
            if ($hook['TargetID'] === $this->InstanceID && $hook['Hook'] !== $WebHook) {
                unset($hooks[$i]);
            }
        }
        if (!$found) {
            $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        }
        IPS_SetProperty($ids[0], 'Hooks', json_encode(array_values($hooks)));
        IPS_ApplyChanges($ids[0]);
    }

    private function UnregisterHookByTarget(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        $new = array_values(array_filter($hooks, fn($h) => $h['TargetID'] !== $this->InstanceID));
        if (count($new) !== count($hooks)) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($new));
            IPS_ApplyChanges($ids[0]);
        }
    }
}
