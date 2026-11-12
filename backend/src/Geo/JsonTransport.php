<?php

namespace App\Geo;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class JsonTransport
{
    public function request(string $url, ?array $form = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $form === null ? 'GET' : 'POST', 'timeout' => 35, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "User-Agent: CalendrierSP/0.1 (sector address extraction)\r\nAccept: application/json\r\n".($form !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''),
            'content' => $form === null ? '' : http_build_query($form),
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) { throw new ServiceUnavailableHttpException(null, 'La source cartographique est indisponible. Réessayez plus tard.'); }
        try {
            $meta = stream_get_meta_data($stream);
            $body = stream_get_contents($stream, 16777217);
            $timedOut = stream_get_meta_data($stream)['timed_out'] ?? false;
        } finally { fclose($stream); }
        if (!preg_match('~^HTTP/\S+ 200(?: |$)~', $meta['wrapper_data'][0] ?? '') || $timedOut || $body === false) {
            throw new ServiceUnavailableHttpException(null, 'La source cartographique n’a pas pu terminer la demande. Réessayez plus tard.');
        }
        if (strlen($body) > 16777216) { throw new \InvalidArgumentException('Trop de données dans cette zone. Réduisez le polygone.'); }
        try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ServiceUnavailableHttpException(null, 'La source cartographique a renvoyé une réponse invalide.'); }
        if (!is_array($data)) { throw new ServiceUnavailableHttpException(null, 'Réponse cartographique invalide.'); }
        return $data;
    }
}
