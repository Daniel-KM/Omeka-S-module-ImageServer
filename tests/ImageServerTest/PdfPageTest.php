<?php declare(strict_types=1);

namespace ImageServerTest;

use ImageServer\Mvc\Controller\Plugin\PdfPage;
use PHPUnit\Framework\TestCase;

class PdfPageTest extends TestCase
{
    public function identifierProvider(): array
    {
        return [
            ['file.pdf;2', ['file.pdf', 2]],
            ['12;3', ['12', 3]],
            ['dir/file.pdf;10', ['dir/file.pdf', 10]],
            ['file.pdf', ['file.pdf', 0]],
            ['file.pdf;0', ['file.pdf;0', 0]],
            ['file;name.jpg', ['file;name.jpg', 0]],
        ];
    }

    /**
     * @dataProvider identifierProvider
     */
    public function testParseIdentifier(string $identifier, array $expected): void
    {
        $this->assertSame($expected, PdfPage::parseIdentifier($identifier));
    }

    public function testFilepath(): void
    {
        $this->assertSame('/files/tile/pdf/abc/2.jpg', PdfPage::filepath('/files', 'tile', 'abc', 2));
    }

    public function testRenderCommandStartsPagesFromZero(): void
    {
        $this->assertSame(
            "/usr/bin/vips copy '/files/original/abc.pdf[page=1,dpi=150]' '/tmp/2.jpg[Q=90,strip]'",
            PdfPage::renderCommand('/usr/bin/vips', '/files/original/abc.pdf', 2, 150, '/tmp/2.jpg')
        );
    }
}
