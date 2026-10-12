<?php

namespace Tests\Unit;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_double_encoded_text_is_repaired(): void
    {
        $broken = "It\u{00E2}\u{0080}\u{0099}s a team \u{00E2}\u{0080}\u{0093} you\u{00E2}\u{0080}\u{0099}ll love it";
        $this->assertSame('It’s a team – you’ll love it', Text::normalizeWhitespace($broken));
    }

    public function test_genuine_accents_and_emoji_are_left_alone(): void
    {
        foreach (['café au lait', 'naïve Zoë', 'Größe 5 – ok', 'São Paulo', 'Deploy 🚀 now', 'Ångström'] as $ok) {
            $this->assertSame($ok, Text::normalizeWhitespace($ok));
        }
    }

    public function test_html_is_reduced_to_text(): void
    {
        $out = Text::htmlToText('<p>Hello <b>world</b></p><ul><li>One</li><li>Two</li></ul><script>alert(1)</script>');
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringContainsString('- One', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertSame('Fish & Chips', Text::htmlToText('Fish &amp; Chips'));
        $this->assertSame('x', Text::htmlToText('&lt;p&gt;x&lt;/p&gt;'), 'entity-encoded HTML (Greenhouse) is decoded, then stripped');
    }

    public function test_canonical_urls_drop_tracking_and_fragments(): void
    {
        $this->assertSame('https://example.com/jobs/1', Text::canonicalUrl('HTTPS://www.Example.com/jobs/1/?utm_source=x&gh_src=y#apply'));
        $this->assertSame('https://example.com/j?id=5', Text::canonicalUrl('https://example.com/j?id=5&utm_campaign=z'));
        $this->assertNull(Text::canonicalUrl('javascript:alert(1)'));
        $this->assertNull(Text::canonicalUrl('ftp://example.com/x'));
    }

    public function test_safe_filename_strips_paths_and_odd_characters(): void
    {
        $this->assertSame('cv.txt', Text::safeFilename('../../etc/cv.txt'));
        $this->assertSame('cv.txt', Text::safeFilename('C:\\Users\\me\\cv.txt'));
        $this->assertStringNotContainsString('<', Text::safeFilename('a<script>.pdf'));
    }
}
