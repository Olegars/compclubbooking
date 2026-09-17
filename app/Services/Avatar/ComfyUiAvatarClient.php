<?php

namespace App\Services\Avatar;

use Illuminate\Support\Facades\Http;

class ComfyUiAvatarClient
{
    /**
     * @param  list<array{bytes: string, name: string}>  $images
     */
    public function generate(array $images, string $prompt, string $negative = ''): ?string
    {
        $base = rtrim((string) config('ai_assistant.avatar.comfyui.url', ''), '/');
        if ($base === '' || $images === []) {
            return null;
        }

        $workflow = $this->graph($this->loadWorkflow());
        if ($workflow === []) {
            return null;
        }

        $timeout = (float) config('ai_assistant.avatar.comfyui.timeout', 90);
        $uploaded = [];
        foreach ($images as $image) {
            $name = $this->upload($base, $image['bytes'], $image['name'], $timeout);
            if ($name === null) {
                return null;
            }
            $uploaded[] = $name;
        }

        $workflow = $this->inject($workflow, $uploaded, $prompt, $negative);
        $promptId = $this->queue($base, $workflow, $timeout);
        if ($promptId === null) {
            return null;
        }

        return $this->waitForPng($base, $promptId, $timeout);
    }

    public function loadImageCount(): int
    {
        $n = 0;
        foreach ($this->graph($this->loadWorkflow()) as $node) {
            if (($node['class_type'] ?? '') === 'LoadImage') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadWorkflow(): array
    {
        $configured = trim((string) config('ai_assistant.avatar.comfyui.workflow', ''));
        $paths = array_values(array_filter([
            $configured !== '' ? $configured : null,
            resource_path('comfyui/avatar_workflow.json'),
        ]));
        foreach ($paths as $path) {
            if (! is_readable($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && $this->graph($decoded) !== []) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  list<string>  $imageNames
     * @return array<string, mixed>
     */
    private function inject(array $workflow, array $imageNames, string $prompt, string $negative): array
    {
        $checkpoint = trim((string) config('ai_assistant.avatar.comfyui.checkpoint', ''));
        $loadIdx = 0;
        $promptSet = false;
        foreach ($workflow as $id => $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['class_type'] ?? '');
            if ($type === 'LoadImage' && isset($imageNames[$loadIdx])) {
                $workflow[$id]['inputs']['image'] = $imageNames[$loadIdx];
                $loadIdx++;
            }
            if ($type === 'CheckpointLoaderSimple' && $checkpoint !== '') {
                $workflow[$id]['inputs']['ckpt_name'] = $checkpoint;
            }
            if (! in_array($type, ['CLIPTextEncode', 'CLIPTextEncodeSDXL'], true)) {
                continue;
            }
            $title = strtolower((string) data_get($node, '_meta.title', ''));
            $text = (string) data_get($node, 'inputs.text', '');
            $isNegative = str_contains($title, 'negative')
                || str_contains($text, '{{NEGATIVE}}')
                || str_contains($text, 'NEGATIVE_PROMPT');
            if ($isNegative) {
                $workflow[$id]['inputs']['text'] = $negative !== '' ? $negative : 'photo, selfie, blurry, extra face, watermark';

                continue;
            }
            if (! $promptSet || str_contains($text, '{{PROMPT}}')) {
                $workflow[$id]['inputs']['text'] = $prompt;
                $promptSet = true;
            }
        }

        return $this->graph($workflow);
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function graph(array $workflow): array
    {
        return array_filter(
            $workflow,
            static fn ($node): bool => is_array($node) && isset($node['class_type'])
        );
    }

    private function upload(string $base, string $bytes, string $name, float $timeout): ?string
    {
        try {
            $response = Http::timeout(min(30.0, $timeout))
                ->attach('image', $bytes, $name, ['image/png'])
                ->post($base.'/upload/image', [
                    'type' => 'input',
                    'overwrite' => 'true',
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $uploaded = is_array($json) ? (string) ($json['name'] ?? '') : '';

        return $uploaded !== '' ? $uploaded : $name;
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function queue(string $base, array $workflow, float $timeout): ?string
    {
        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($base.'/prompt', [
                    'prompt' => $workflow,
                    'client_id' => 'club-avatar',
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $id = (string) data_get($response->json(), 'prompt_id', '');

        return $id !== '' ? $id : null;
    }

    private function waitForPng(string $base, string $promptId, float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        do {
            try {
                $response = Http::timeout(15)->acceptJson()->get($base.'/history/'.$promptId);
            } catch (\Throwable) {
                return null;
            }
            if ($response->successful()) {
                $png = $this->pngFromHistory($base, $promptId, $response->json());
                if ($png !== null) {
                    return $png;
                }
                if (data_get($response->json(), $promptId.'.status.status_str') === 'error') {
                    return null;
                }
            }
            if (app()->runningUnitTests()) {
                return null;
            }
            usleep(400_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function pngFromHistory(string $base, string $promptId, ?array $json): ?string
    {
        $outputs = data_get($json, $promptId.'.outputs');
        if (! is_array($outputs)) {
            $outputs = data_get($json, 'outputs');
        }
        if (! is_array($outputs)) {
            return null;
        }
        foreach ($outputs as $node) {
            $images = is_array($node) ? ($node['images'] ?? []) : [];
            if (! is_array($images)) {
                continue;
            }
            foreach ($images as $image) {
                if (! is_array($image)) {
                    continue;
                }
                $filename = (string) ($image['filename'] ?? '');
                if ($filename === '') {
                    continue;
                }
                try {
                    $file = Http::timeout(30)->get($base.'/view', [
                        'filename' => $filename,
                        'subfolder' => (string) ($image['subfolder'] ?? ''),
                        'type' => (string) ($image['type'] ?? 'output'),
                    ]);
                } catch (\Throwable) {
                    continue;
                }
                if ($file->successful() && $this->looksLikeImage($file->body())) {
                    return $file->body();
                }
            }
        }

        return null;
    }

    private function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG")
            || str_starts_with($bytes, "\xff\xd8\xff")
            || str_starts_with($bytes, 'GIF8')
            || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }
}
