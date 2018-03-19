<?php
/*
Plugin Name: https replacer
*/

declare(strict_types=1);

/**
 * attribute => tag(s), HTML4, HTML5
 * one url in each attribute
 */
define("NORM_URI", [
    "action" => ["form"],
    "archive" => ["applet", "object"],
    "background" => ["body"],
    "cite" => ["blockquote", "del", "ins", "q"],
    "classid" => ["object"],
    "codebase" => ["applet", "object"],
    "content" => ["meta"],
    "data" => ["object"],
    "formaction" => ["button", "input"],
    "href" => ["a", "area", "base", "image", "link"],
    "icon" => ["command"],
    "longdesc" => ["frame", "iframe", "img"],
    "manifest" => ["html"],
    "poster" => ["video"],
    "profile" => ["head"],
    "src" => ["audio", "embed", "frame", "iframe", "img", "input", "script", "source", "track", "video"],
    "usemap" => ["img", "input", "object"]
]);

/**
 * attribute => tag(s), HTML4, HTML5
 * multiple url in each attribute
 */
define("SPECIAL_URI", [
    "archive" => ["applet", "object"],
    "content" => ["meta"],
    "srcset" => ["img", "source"]
]);


libxml_use_internal_errors(true);

/*
$post_query = new WP_Query([
    "post_status" => ["featured", "publish"],
    "post_type" => "any",
    "nopaging" => true
]);
while ($post_query->have_posts()) {
    $post_query->the_post();
    echo "<p>" . the_title() . "</p>";
}
wp_reset_postdata();
*/

replaceURL("http://classicrock.net");


/**
 * @param string $url
 */
function replaceURL(string $url): void
{

    /**
     * @param array $meta
     * @return bool
     */
    function isCompressed(array $meta): bool
    {
        return count(preg_grep("/Content-Encoding:\s*(gzip|deflate)/i", $meta)) > 0;
    }

    $context = stream_context_create([
        "http" => [
            "header" => [
                "Connection: close",
                "Accept-encoding: gzip, deflate",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "User-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_3) AppleWebKit/604.5.6 (KHTML, like Gecko) Version/11.0.3 Safari/604.5.6",
                "Referer: https://www.google.de",
                "Dnt: 1",
                "Accept-language: en-us"
            ],
            "ignore_errors" => true,
            "max_recirects" => 5,
            "method" => "GET",
            "protocol_version" => 2.0,
            "timeout" => 20.0
        ]
    ]);
    $fd = @fopen($url, "rb", false, $context);
    if ($fd) {
        $meta = stream_get_meta_data($fd)["wrapper_data"];
        $content = @stream_get_contents($fd);
        fclose($fd);
        if (strlen($content) > 0) {
            $content = isCompressed($meta) ? zlib_decode($content) : $content;
            if ($content !== false) {
                replaceText(getFinalLocation($meta, $url) ?? "https://www.google.de", $content);
            }
        }
    }
}


function replaceText(string $referer, string $html): void
{
    $buffer = [];
    $doc = new DOMDocument("1.0", "UTF-8");
    $doc->resolveExternals = true;
    $doc->substituteEntities = false;
    $doc->encoding = "UTF-8";
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    // $html = mb_convert_encoding($html, "UTF-8", mb_detect_encoding($html, mb_detect_order(), true));
    $doc->loadHTML($html, LIBXML_NOENT | LIBXML_NOEMPTYTAG | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($doc);
    replaceNormURL($referer, $xpath, $buffer);
    replaceSpecialURL($referer, $xpath, $buffer);
    replaceStyleURL($referer, $xpath, $buffer);
    var_dump($buffer);
//    echo $doc->saveHTML();
}


function replaceNormURL(string $referer, DOMXPath &$xpath, array &$buffer): void
{

    /**
     * @return string
     */
    function assembleXpathNormQuery(): string
    {
        $query = [];
        foreach (NORM_URI as $attribute => $tags) {
            $tag = implode(" or self::", $tags);
            $query[] = "(//*[self::{$tag}][starts-with(normalize-space(@{$attribute}),'//') or starts-with(normalize-space(@{$attribute}),'http://')]/@{$attribute})";
        }
        return (implode("|", $query));
    }

    $nodes = $xpath->query(assembleXpathNormQuery());
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $n) {
            $atr = preg_replace("/\s+/", "", $n->value);
            $r = (array_key_exists($atr, $buffer)) ? $buffer[$atr] : redirect($referer, $atr);
            if ($r && ($r !== $atr)) {
                $n->parentNode->setAttribute($n->name, $r);
                $buffer[$atr] = $r;
            } else {
                $buffer[$atr] = null;
            }
        }
    }
}


function replaceSpecialURL(string $referer, DOMXPath &$xpath, array &$buffer): void
{

    /**
     * @return string
     */
    function assembleXpathSpecialQuery(): string
    {
        $query = [];
        foreach (SPECIAL_URI as $attribute => $tags) {
            $tag = implode(" or self::", $tags);
            $query[] = "(//*[self::{$tag}][contains(@{$attribute},'http://')]/@{$attribute})";
        }
        return (implode("|", $query));
    }

    $nodes = $xpath->query(assembleXpathSpecialQuery());
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $n) {
            $atr = $n->value;
            if (preg_match_all("%(?:http:)?//\S+%i", $n->value, $treffer, PREG_SET_ORDER)) {
                $changed = false;
                foreach ($treffer as $a) {
                    $r = (array_key_exists($atr, $buffer)) ? $buffer[$a[0]] : redirect($referer, $a[0]);
                    if ($r && ($r !== $a[0])) {
                        $atr = str_replace($a[0], $r, $atr);
                        $buffer[$a[0]] = $atr;
                        $changed = true;
                    } else {
                        $buffer[$a[0]] = null;
                    }
                }
                if ($changed) {
                    $n->parentNode->setAttribute($n->name, $atr);
                }
            }
        }
    }
}


function replaceStyleURL(string $referer, DOMXPath &$xpath, array &$buffer): void
{

    /**
     * @return string
     */
    function assembleXpathStyleQuery(): string
    {
        $query = "(//*[contains(@style,'url') and contains(@style,'http://')]/@style)";
        return $query;
    }

    $nodes = $xpath->query(assembleXpathStyleQuery());
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $n) {
            $atr = $n->value;
            if (preg_match_all("%(url\s*\(\s*)?((?:http:)?//[^)\s]+)(?(1)\s*\)\s*)?%i", $atr, $treffer, PREG_SET_ORDER)) {
                $changed = false;
                foreach ($treffer as $a) {
                    $r = (array_key_exists($atr, $buffer)) ? $buffer[$a[2]] : redirect($referer, $a[2]);
                    if ($r && ($r !== $a[2])) {
                        $atr = str_replace($a[2], $r, $atr);
                        $buffer[$a[2]] = $atr;
                        $changed = true;
                    } else {
                        $buffer[$a[2]] = null;
                    }
                }
                if ($changed) {
                    $n->parentNode->setAttribute($n->name, $atr);
                }
            }
        }
    }
}


function redirect(string $referer, string $url): ?string
{
    $context = stream_context_create([
        "http" => [
            "header" => [
                "Connection: close",
                "Accept-encoding: gzip, deflate",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "User-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_3) AppleWebKit/604.5.6 (KHTML, like Gecko) Version/11.0.3 Safari/604.5.6",
                "Referer: {$referer}",
                "Dnt: 1",
                "Accept-language: en-us"
            ],
            "ignore_errors" => true,
            "max_recirects" => 5,
            "method" => "GET",
            "protocol_version" => 2.0,
            "timeout" => 20.0
        ]
    ]);
    $result = null;
    $newurl = preg_replace("%^(?:https?:)?//%i", "https://", $url);
    if ($newurl) {
        $fd = @fopen($newurl, "rb", false, $context);
        if ($fd) {
            $meta = stream_get_meta_data($fd)["wrapper_data"];
            fclose($fd);
            $result = getFinalLocation($meta, $newurl);
        }
    }
    return $result;
}


/**
 * @param array $meta
 * @param string $newurl
 * @return null|string
 */
function getFinalLocation(array $meta, string $newurl): ?string
{
    $result = null;
    if (preg_match_all("/(?:HTTP\/[12](?:\.[01])?|Location:)\s+(\S+)/i", implode("\n", $meta), $treffer, PREG_PATTERN_ORDER)) {
        if (array_pop($treffer[1]) == 200) {
            if (count($treffer[1]) > 0) {
                $loc = array_pop($treffer[1]);
                if (preg_match("%^https?://%i", $loc)) {
                    $result = $loc;
                }
            } else {
                $result = $newurl;
            }
        }
    }
    return $result;
}
