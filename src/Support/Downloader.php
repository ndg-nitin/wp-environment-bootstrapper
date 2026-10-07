<?php

declare(strict_types=1);

namespace WpEnvironment\Support;

use WpEnvironment\Core\SetupException;

/**
 * Downloads a file to a local temporary path.
 *
 * Used for user-provided private plugin/theme ZIPs and for the official
 * ACF Pro download endpoint (with the user's own license key). Nothing is
 * ever downloaded by the repository itself - this only fetches sources the
 * user configured.
 */
final class Downloader
{
    private const TIMEOUT_SECONDS = 120;

    /**
     * Download $url to $destination and return the local path.
     *
     * @param string      $url         Remote URL.
     * @param string      $destination Local file path (must not exist).
     * @param string|null $displayUrl  Redacted URL used in error messages.
     * @throws SetupException
     * @return string The destination path.
     */
    public static function download(string $url, string $destination, ?string $displayUrl = null): string
    {
        $display = $displayUrl ?? $url;
        $dir     = dirname($destination);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new SetupException(
                sprintf('Unable to create temporary directory "%s" for the download.', $dir),
                ['Check that the system temporary directory is writable.']
            );
        }

        $error = null;

        if (extension_loaded('curl')) {
            $error = self::downloadWithCurl($url, $destination, $display);
        } else {
            $error = self::downloadWithStreams($url, $destination, $display);
        }

        if ($error !== null) {
            @unlink($destination);

            $hints = [
                'Verify that the source URL is correct and publicly reachable (authenticated/private URLs must be downloadable by this machine).',
            ];

            if (preg_match('/\bHTTP\s*([1-5]\d{2})\b/', $error, $status) === 1) {
                $code = (int) $status[1];

                if ($code === 401 || $code === 403 || $code === 404 || $code === 410) {
                    $hints[] = 'The server refused the request (HTTP ' . $code . ') - for licensed products this '
                        . 'usually means the license key is invalid, expired, or not allowed to download from this machine.';
                } elseif ($code >= 500) {
                    $hints[] = 'The server failed to answer (HTTP ' . $code . ') - try again in a moment.';
                } else {
                    array_unshift($hints, 'Check that the URL points at the file itself rather than a login or listing page.');
                }
            } else {
                array_unshift($hints, 'Check your internet connection.');
            }

            if ($error !== '') {
                $hints[] = 'Server said: ' . $error;
            }

            throw new SetupException(sprintf('Download failed: %s', $display), $hints);
        }

        if (!is_file($destination) || filesize($destination) === 0) {
            @unlink($destination);

            throw new SetupException(
                sprintf('Download failed: %s returned an empty file.', $display),
                [
                    'Verify that the URL points directly at a file (ZIP) rather than an HTML page.',
                    'For licensed products, verify that your license key is valid.',
                ]
            );
        }

        return $destination;
    }

    /**
     * @return string|null Error detail, or null on success.
     */
    private static function downloadWithCurl(string $url, string $destination, string $display): ?string
    {
        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            return sprintf('Unable to open "%s" for writing.', $destination);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => 'wp-environment-bootstrapper',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);

        curl_close($ch);
        fclose($handle);

        if ($ok === false) {
            return $err;
        }

        if ($code >= 400) {
            return sprintf('HTTP %d from %s', $code, $display);
        }

        return null;
    }

    /**
     * Fallback for environments without the curl extension.
     *
     * @return string|null Error detail, or null on success.
     */
    private static function downloadWithStreams(string $url, string $destination, string $display): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => 'wp-environment-bootstrapper',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            $lastError = error_get_last();

            return $lastError['message'] ?? sprintf('Unable to fetch %s', $display);
        }

        if (@file_put_contents($destination, $data) === false) {
            return sprintf('Unable to write "%s".', $destination);
        }

        return null;
    }
}
