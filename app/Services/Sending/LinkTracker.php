<?php

namespace App\Services\Sending;

/**
 * Rewrites http(s) links in an HTML email through the click tracker and
 * appends the open pixel.
 */
class LinkTracker
{
    /**
     * @param  callable(string): string  $trackUrl  Original URL → tracked URL
     * @param  list<string>  $skip  URLs to leave untouched (e.g. unsubscribe)
     */
    public function rewriteLinks(string $html, callable $trackUrl, array $skip = []): string
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i',
            function (array $match) use ($trackUrl, $skip): string {
                $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (in_array($url, $skip, true)) {
                    return $match[0];
                }

                return $match[1].$match[2].e($trackUrl($url)).$match[2];
            },
            $html,
        ) ?? $html;
    }

    public function appendPixel(string $html, string $pixelUrl): string
    {
        return $html.'<img src="'.e($pixelUrl).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
    }
}
