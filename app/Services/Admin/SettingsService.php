<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;

class SettingsService
{
    public function getAllSettingsServ(Request $request)
    {
        $keys = [];
        if(config('custom.demo')){
            foreach($this->getKeys() as $key => $value){
                $value['value'] = __('locale.demo_error');
                $keys[$key] = $value;
            }
        } else {
            $keys = $this->getKeys();
        }
        return $keys;
    }

    public function saveServ(Request $request)
    {
        if(config('custom.demo')){
            return (['success' => false, 'message' => __('locale.demo_error')]);
        }
        $result = null;
        parse_str($request->data, $result);
        $this->writeKeys($result ?: []);
        return (['success' => true, 'message' => __('locale.admin_settings_success')]);
    }

    private function getKeys(): array
    {
        $path = base_path('.env');
        if (!is_readable($path)) {
            return [];
        }

        $keys = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                continue;
            }
            $keys[$matches[1]] = ['value' => trim($matches[2], "\"'")];
        }
        return $keys;
    }

    private function writeKeys(array $values): void
    {
        $path = base_path('.env');
        $lines = is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $updates = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                $updates[$key] = json_encode((string) $value, JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ($lines as &$line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches) && array_key_exists($matches[1], $updates)) {
                $line = $matches[1].'='.$updates[$matches[1]];
                unset($updates[$matches[1]]);
            }
        }
        unset($line);
        foreach ($updates as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to update environment settings.');
        }
    }
}
