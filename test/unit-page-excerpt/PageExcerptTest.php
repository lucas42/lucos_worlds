<?php

namespace LucosTests\PageExcerpt;

// Standalone PHPUnit test for lucas42/lucos_worlds#52: Page::getExcerpt()
// stops at the first newline in the page's auto-derived `text` field before
// applying the existing length truncation, so a page opened with a short
// summary paragraph followed by a list/table doesn't have that list/table
// content bled into its excerpt/og:description.
//
// This deliberately instantiates the real, patched BookStack\Entities\Models\
// Page class directly (bare `new Page()` + attribute assignment) rather than
// mocking it — Eloquent models can be constructed and have plain attributes
// read/written without a booted Laravel app or database connection, as long
// as no relationship/query method is touched, and getExcerpt() only reads
// the `text` attribute. Run directly against the real, patched image (see
// unit.Dockerfile / build-and-run.sh), same pattern as
// test/unit-oidc-alg-binding/.

use BookStack\Entities\Models\Page;
use PHPUnit\Framework\TestCase;

class PageExcerptTest extends TestCase
{
    protected static function pageWithText(string $text): Page
    {
        $page = new Page();
        $page->text = $text;

        return $page;
    }

    public function test_single_paragraph_page_is_truncated_by_length_as_before()
    {
        $page = self::pageWithText('A short one-line summary of this page.');

        $this->assertSame('A short one-line summary of this page.', $page->getExcerpt());
    }

    public function test_summary_line_followed_by_a_list_only_returns_the_summary_line()
    {
        $page = self::pageWithText(
            "A short one-line summary of this page.\nFirst list item\nSecond list item\nThird list item"
        );

        $this->assertSame('A short one-line summary of this page.', $page->getExcerpt());
    }

    public function test_summary_line_followed_by_a_table_only_returns_the_summary_line()
    {
        // HtmlToPlainText puts each table cell/row on its own line the same
        // way it does list items — this is the "table" half of #52's title,
        // covered separately from the list case since they're rendered via
        // different HTML tags upstream even though the fix treats both
        // identically (anything after the first newline).
        $page = self::pageWithText("Overview of the region.\nCapital\nPopulation\nRuler");

        $this->assertSame('Overview of the region.', $page->getExcerpt());
    }

    public function test_first_line_longer_than_the_limit_is_still_truncated_with_ellipsis()
    {
        $longFirstLine = str_repeat('a', 150);
        $page = self::pageWithText($longFirstLine . "\nSome list item");

        $excerpt = $page->getExcerpt(100);

        $this->assertSame(str_repeat('a', 97) . '...', $excerpt);
        $this->assertStringNotContainsString('list item', $excerpt);
    }

    public function test_respects_a_custom_length_argument()
    {
        $page = self::pageWithText('A short one-line summary of this page.');

        $this->assertSame('A short...', $page->getExcerpt(10));
    }

    public function test_empty_text_returns_empty_string()
    {
        $page = self::pageWithText('');

        $this->assertSame('', $page->getExcerpt());
    }

    public function test_leading_and_trailing_whitespace_is_trimmed()
    {
        $page = self::pageWithText("  A short one-line summary of this page.  \nFirst list item");

        $this->assertSame('A short one-line summary of this page.', $page->getExcerpt());
    }
}
