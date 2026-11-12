<?php

namespace App\Geo;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Server-configured public data files, cached atomically after a complete download. */
final class CachedDownload
{
    public function __construct(private string $cacheDir) {}

    public function file(string $url, bool $gzip = false): string
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0770, true) && !is_dir($this->cacheDir)) {
            throw $this->unavailable();
        }
        $path = $this->cacheDir.'/'.hash('sha256', $url).($gzip ? '.csv' : '.json');
        $lock = @fopen($path.'.lock', 'c+');
        if (!$lock) { throw $this->unavailable(); }
        $input = $output = null;
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw $this->unavailable(); }
            clearstatcache(true, $path);
            if (is_file($path) && filemtime($path) > time() - 86400) { return $path; }
            $deadline = microtime(true) + 45;
            $context = stream_context_create(['http' => [
                'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => "User-Agent: CalendrierSP/0.1 (sector address extraction)\r\nConnection: close\r\n",
            ]]);
            $input = @fopen($url, 'rb', false, $context);
            if (!$input || !preg_match('~^HTTP/\S+ 200(?: |$)~', stream_get_meta_data($input)['wrapper_data'][0] ?? '')) { throw $this->unavailable(); }
            $temporary = tempnam($this->cacheDir, 'download-');
            $output = $temporary === false ? false : @fopen($temporary, 'wb');
            if (!$output) { throw $this->unavailable(); }
            $decoder = $gzip ? inflate_init(ZLIB_ENCODING_GZIP) : null;
            $received = $written = 0;
            while (!feof($input)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) { throw $this->unavailable(); }
                stream_set_timeout($input, max(1, (int) ceil($remaining)));
                $chunk = fread($input, 65536);
                if ($chunk === false || (stream_get_meta_data($input)['timed_out'] ?? false)) { throw $this->unavailable(); }
                if ($chunk === '') {
                    if (feof($input)) { break; }
                    throw $this->unavailable();
                }
                $received += strlen($chunk);
                if ($received > ($gzip ? 67108864 : 16777216)) { throw $this->unavailable(); }
                $decoded = $gzip ? @inflate_add($decoder, $chunk) : $chunk;
                if ($decoded === false) { throw $this->unavailable(); }
                $written += strlen($decoded);
                if ($written > ($gzip ? 536870912 : 16777216) || fwrite($output, $decoded) !== strlen($decoded)) { throw $this->unavailable(); }
            }
            if ($received === 0 || ($gzip && (inflate_get_status($decoder) !== ZLIB_STREAM_END || inflate_get_read_len($decoder) !== $received))) { throw $this->unavailable(); }
            fclose($output); $output = null;
            if (!rename($temporary, $path)) { throw $this->unavailable(); }
            $temporary = null;
            return $path;
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function unavailable(): ServiceUnavailableHttpException
    {
        return new ServiceUnavailableHttpException(null, 'Le téléchargement des adresses officielles est indisponible ou incomplet. Aucun import effectué ; réessayez plus tard.');
    }
}
