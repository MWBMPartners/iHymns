<?php

declare(strict_types=1);

/**
 * iHymns — remove HTML comments from pages before they are sent
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The PHP templates carry a lot of `<!-- ... -->` notes written for the people
 * who maintain the code: issue numbers, file paths, design decisions. Comments
 * written in PHP never leave the server, but HTML comments are
 * sent to every visitor and show up in "View source". Nothing in the app reads
 * them, so they are only extra bytes, and notes meant for developers.
 *
 * ONE place does the removal: index.php (the page shell) and api.php (the page
 * fragments it loads) both pass their output through ihymnsStripHtmlComments().
 *
 * WHAT IS LEFT ALONE:
 *   - anything inside <script>, <style>, <pre> and <textarea>, where "<!--"
 *     can be real content (a string in a script, a code sample, typed text);
 *   - old Internet Explorer conditional comments (`<!--[if ...]>`).
 * If the pattern match ever fails (for example on an unusually large page), the
 * page is sent exactly as it was — removing comments is never worth breaking a
 * page over.
 *
 * @see https://www.php.net/manual/en/function.ob-start.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Comments
 */

if (!function_exists('ihymnsStripHtmlComments')) {
    /**
     * Return $html with its HTML comments removed (see the file note above).
     *
     * @param string $html A whole page, or a page fragment.
     * @return string The same markup without `<!-- ... -->` comments.
     */
    function ihymnsStripHtmlComments(string $html): string
    {
        /* Quick exit: most JSON replies and many fragments have no comments. */
        if (strpos($html, '<!--') === false) {
            return $html;
        }

        /* Read the page left to right, the way a browser does. At each point,
           whichever starts first wins: a protected block (script/style/pre/
           textarea, kept whole) or a comment (removed). Because a comment is
           matched as a whole, a "<script>" that is merely mentioned inside a
           comment's text can't be mistaken for a real one, and a "<!--" inside
           a real script stays put. */
        $out = preg_replace_callback(
            '#<(script|style|pre|textarea)\b[^>]*>.*?</\1\s*>|<!--(?!\[if\b).*?-->#is',
            static fn(array $m): string => (($m[1] ?? '') !== '') ? $m[0] : '',
            $html
        );
        return $out ?? $html;   /* null = the match failed: send the page unchanged */
    }
}
