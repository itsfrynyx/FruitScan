<?php
declare(strict_types=1);
final class PredictionService {
    public function analyzeImage(string $path, string $mime, string $demoOutcome): array {
        if (is_demo()) {
            return ['prediction' => $demoOutcome === 'rotten' ? 'rotten' : 'fresh', 'confidence' => null, 'fruit_class' => 'Unclassified fruit', 'probabilities' => null, 'model_version' => 'Interactive demo · no AI model', 'is_demo' => 1];
        }
        $configuration = prediction_configuration();
        $url = $configuration['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) throw new RuntimeException('Invalid model configuration.');
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if (!empty($configuration['token'])) $headers[] = 'Authorization: Bearer ' . $configuration['token'];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['image' => new CURLFile($path, $mime, 'fruit')], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status !== 200 || !is_array($data) || ($data['success'] ?? false) !== true || !in_array($data['prediction'] ?? '', ['fresh','rotten'], true)) throw new RuntimeException('Model returned an invalid response.');
        if (isset($data['model_version']) && $data['model_version'] !== (model_config()['version'] ?? '')) throw new RuntimeException('Running model does not match the configured model manifest.');
        $confidence = $data['confidence'] ?? null;
        if ($confidence !== null && (!is_numeric($confidence) || $confidence < 0 || $confidence > 1)) throw new RuntimeException('Invalid confidence.');
        $classes = model_config()['fruit_classes'] ?? [];
        $fruit = $data['fruit_class'] ?? 'Unclassified fruit';
        if (!is_string($fruit) || ($fruit !== 'Unclassified fruit' && !in_array($fruit, $classes, true))) throw new RuntimeException('Model class is not in the manifest.');
        $probs = $data['probabilities'] ?? null;
        if ($probs !== null) {
            if (!is_array($probs) || !isset($probs['fresh'], $probs['rotten']) || !is_numeric($probs['fresh']) || !is_numeric($probs['rotten']) || min($probs['fresh'], $probs['rotten']) < 0 || max($probs['fresh'], $probs['rotten']) > 1 || abs($probs['fresh']+$probs['rotten']-1) > .02) throw new RuntimeException('Invalid probabilities.');
            $probs = ['fresh' => (float)$probs['fresh'], 'rotten' => (float)$probs['rotten']];
        }
        return ['prediction' => $data['prediction'], 'confidence' => $confidence === null ? null : (float)$confidence, 'fruit_class' => $fruit, 'probabilities' => $probs, 'model_version' => clean(model_config()['version'] ?? 'Connected model'), 'is_demo' => 0];
    }
}
