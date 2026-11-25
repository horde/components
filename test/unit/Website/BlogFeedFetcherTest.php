<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Website;

use Horde\Components\Website\BlogFeedFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlogFeedFetcher::class)]
class BlogFeedFetcherTest extends TestCase
{
    public function testFetchStripsPhpWarningMarkupBeforeXml(): void
    {
        $warning = '<br />' . "\n"
            . '<b>Warning</b>: preg_match(): Foo failed in '
            . '<b>/path/to/rockme/amadeus.php</b> on line <b>112178</b><br />' . "\n";
        $error = null;

        $posts = $this->fetchResponse($warning . $this->feedXml(), $error);

        self::assertNull($error);
        self::assertCount(1, $posts);
        self::assertSame('Example post', $posts[0]->title);
    }

    public function testFetchDoesNotStripOtherOutputBeforeXml(): void
    {
        $error = null;

        $posts = $this->fetchResponse("Unexpected output\n" . $this->feedXml(), $error);

        self::assertSame([], $posts);
        self::assertStringStartsWith('Failed to parse feed XML: file://', (string) $error);
    }

    private function fetchResponse(string $response, ?string &$error): array
    {
        $path = tempnam(sys_get_temp_dir(), 'horde-blog-feed-');
        self::assertNotFalse($path);

        try {
            self::assertNotFalse(file_put_contents($path, $response));
            return (new BlogFeedFetcher())->fetch('file://' . $path, 'Test feed', $error);
        } finally {
            unlink($path);
        }
    }

    private function feedXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0"><channel><item>'
            . '<title>Example post</title><link>https://example.test/post</link>'
            . '<pubDate>Tue, 25 Nov 2025 09:00:00 +0000</pubDate>'
            . '</item></channel></rss>';
    }
}
