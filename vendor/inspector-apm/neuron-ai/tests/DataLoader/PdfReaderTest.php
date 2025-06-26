<?php

namespace NeuronAI\Tests\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\DataLoader\ReaderInterface;
use PHPUnit\Framework\TestCase;

class PdfReaderTest extends TestCase
{
    public function skipIfPdfToTextNotFound()
    {
        $commonPaths = [
            '/usr/bin/pdftotext',          // Common on Linux
            '/usr/local/bin/pdftotext',    // Common on Linux
            '/opt/homebrew/bin/pdftotext', // Homebrew on macOS (Apple Silicon)
            '/opt/local/bin/pdftotext',    // MacPorts on macOS
            '/usr/local/bin/pdftotext',    // Homebrew on macOS (Intel)
        ];

        foreach ($commonPaths as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        $this->markTestSkipped('The pdftotext binary was not found on this machine.');
    }

    public function test_instance()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $this->assertInstanceOf(PdfReader::class, $instance);
        $this->assertInstanceOf(ReaderInterface::class, $instance);
    }

    public function test_set_pdf()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $instance = $instance->setPdf(__DIR__ . '/test.pdf');
        $this->assertInstanceOf(PdfReader::class, $instance);
    }

    public function test_set_pdf_exception()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $this->expectException(DataReaderException::class);
        $instance->setPdf('');
    }

    public function test_get_text()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $text = $instance->getText(__DIR__ . '/test.pdf');
        $this->assertStringEqualsFile(__DIR__. '/target.txt', $text . PHP_EOL);
    }

    public function test_get_text_with_image()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $text = $instance->getText(__DIR__ . '/test-with-image.pdf');
        $this->assertStringEqualsFile(__DIR__. '/target.txt', $text . PHP_EOL);
    }

    public function test_get_text_exception()
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $this->expectException(DataReaderException::class);
        $instance->getText(__DIR__.'/test.pdf', ['binPath' => 'path/to/bin']);
    }
}
