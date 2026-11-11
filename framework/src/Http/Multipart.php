<?php

declare(strict_types=1);

namespace Loom;

/**
 * 从已读出的正文中解析 multipart 与 urlencoded 表单。
 */
final class Multipart
{
    /**
     * @return array{fields: array<string, list<string>>, files: array<string, list<UploadedFile>>}
     */
    public static function parse(string $contentType, string $body): array
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if ($type === 'application/x-www-form-urlencoded') {
            return ['fields' => self::parseUrlEncoded($body), 'files' => []];
        }
        if ($type === 'multipart/form-data') {
            return self::parseMultipart($contentType, $body);
        }
        throw new HttpError(415, 'Unsupported media type for form');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseUrlEncoded(string $body): array
    {
        $fields = [];
        foreach (explode('&', $body) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            $name = urldecode($pair[0]);
            $value = urldecode($pair[1] ?? '');
            if (!isset($fields[$name])) {
                $fields[$name] = [];
            }
            $fields[$name][] = $value;
        }
        return $fields;
    }

    /**
     * @return array{fields: array<string, list<string>>, files: array<string, list<UploadedFile>>}
     */
    private static function parseMultipart(string $contentType, string $body): array
    {
        $boundary = self::boundary($contentType);
        if ($boundary === '') {
            throw new HttpError(400, 'Missing multipart boundary');
        }
        $delimiter = '--' . $boundary;
        $fields = [];
        $files = [];
        $parts = explode($delimiter, $body);
        foreach ($parts as $index => $part) {
            if ($index === 0 || $part === '' || $part === '--' || $part === "--\r\n") {
                continue;
            }
            $part = ltrim($part, "\r\n");
            if (str_ends_with($part, '--')) {
                $part = substr($part, 0, -2);
            }
            $segments = preg_split("/\r\n\r\n|\n\n/", $part, 2);
            if (!is_array($segments) || count($segments) < 2) {
                throw new HttpError(400, 'Malformed multipart body');
            }
            [$rawHeaders, $content] = $segments;
            if (str_ends_with($content, "\r\n")) {
                $content = substr($content, 0, -2);
            } elseif (str_ends_with($content, "\n")) {
                $content = substr($content, 0, -1);
            }
            $headers = self::parsePartHeaders($rawHeaders);
            $disposition = $headers['content-disposition'] ?? '';
            if ($disposition === '') {
                continue;
            }
            $name = self::parameter($disposition, 'name');
            if ($name === '') {
                continue;
            }
            $filename = self::parameter($disposition, 'filename');
            if ($filename !== '') {
                $fileType = $headers['content-type'] ?? 'application/octet-stream';
                if (!isset($files[$name])) {
                    $files[$name] = [];
                }
                $files[$name][] = new UploadedFile($filename, $fileType, strlen($content), $content, $name);
                continue;
            }
            if (!isset($fields[$name])) {
                $fields[$name] = [];
            }
            $fields[$name][] = $content;
        }
        return ['fields' => $fields, 'files' => $files];
    }

    private static function boundary(string $contentType): string
    {
        foreach (explode(';', $contentType) as $piece) {
            $piece = trim($piece);
            if (str_starts_with(strtolower($piece), 'boundary=')) {
                $value = substr($piece, 9);
                $value = trim($value, "\"'");
                return $value;
            }
        }
        return '';
    }

    /**
     * @return array<string, string>
     */
    private static function parsePartHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split("/\r\n|\n/", $raw) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name] = trim(substr($line, $colon + 1));
        }
        return $headers;
    }

    private static function parameter(string $header, string $key): string
    {
        if (!preg_match('/\b' . preg_quote($key, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^;\s]+))/i', $header, $matches)) {
            return '';
        }
        if ($matches[1] !== '') {
            return $matches[1];
        }
        if ($matches[2] !== '') {
            return $matches[2];
        }
        return $matches[3];
    }
}
