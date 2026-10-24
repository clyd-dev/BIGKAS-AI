<?php

namespace App\Support;

/**
 * A URL for a file in public/ that changes when the file does.
 *
 * asset() alone returns the same URL for ever, so a browser that has
 * public/js/batch-board.js cached goes on running the old one after a deploy -
 * and a page whose markup and whose script disagree is worse than either on its
 * own. The batch panel showed exactly that: the server rendered the card
 * correctly, the cached script fetched a different card from the board and
 * swapped it in, so a reading that finished recording got buttons back that
 * had been taken out.
 *
 * The file's own mtime is the version, so nothing has to be remembered or bumped
 * by hand - every deploy that changes a file changes its URL, and one that
 * doesn't leaves the cache alone. A missing file falls back to the plain URL
 * rather than throwing: a wrong path is a 404 to be seen, not a 500.
 */
final class Asset
{
    /** path => resolved URL, so one request stats a file once. */
    private static array $resolved = [];

    public static function url(string $path): string
    {
        if (isset(self::$resolved[$path])) {
            return self::$resolved[$path];
        }

        $url = asset($path);
        $file = public_path($path);

        if (is_file($file)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        }

        return self::$resolved[$path] = $url;
    }
}
