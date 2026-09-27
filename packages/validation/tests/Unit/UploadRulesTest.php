<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\MaxFileSize;
use Hydra\Validation\Rules\MimeType;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Rules\PresentValue;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Rules\UploadedFile;
use Hydra\Validation\Validator;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as Upload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules an uploaded file is checked against. The client names the file
 * and declares its type, and neither is believed: the type comes from the
 * bytes, and the size from the stream.
 */
#[CoversClass(UploadedFile::class)]
#[CoversClass(MaxFileSize::class)]
#[CoversClass(MimeType::class)]
#[CoversTrait(PresentValue::class)]
final class UploadRulesTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context([], 'avatar');
    }

    public function test_a_successful_upload_passes(): void
    {
        $this->assertNull((new UploadedFile)->validate($this->png(), $this->context));
    }

    /** @param int<0, 8> $error */
    #[DataProvider('failedUploads')]
    public function test_each_upload_error_reads_as_a_sentence(int $error, string $expected): void
    {
        $message = (new UploadedFile)->validate($this->failed($error), $this->context);

        $this->assertSame($expected, $message);
    }

    /** @return iterable<string, array{int, string}> */
    public static function failedUploads(): iterable
    {
        yield 'ini size' => [UPLOAD_ERR_INI_SIZE, 'The file is larger than the server accepts.'];
        yield 'form size' => [UPLOAD_ERR_FORM_SIZE, 'The file is larger than the server accepts.'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, 'The upload was interrupted. Try again.'];
        yield 'no file' => [UPLOAD_ERR_NO_FILE, 'Choose a file to upload.'];
        yield 'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, 'The server could not save the upload.'];
        yield 'cant write' => [UPLOAD_ERR_CANT_WRITE, 'The server could not save the upload.'];
        yield 'extension' => [UPLOAD_ERR_EXTENSION, 'The server could not save the upload.'];
    }

    public function test_something_that_is_not_an_upload_fails(): void
    {
        $this->assertSame('Choose a file to upload.', (new UploadedFile)->validate('avatar.png', $this->context));
        $this->assertNotNull((new MaxFileSize(10))->validate('avatar.png', $this->context));
        $this->assertNotNull((new MimeType('image/png'))->validate('avatar.png', $this->context));
    }

    public function test_the_size_limit_is_inclusive(): void
    {
        $size = strlen(base64_decode(self::PNG));

        $this->assertNull((new MaxFileSize($size))->validate($this->png(), $this->context));
        $this->assertSame(
            'The file must be 67 bytes or smaller.',
            (new MaxFileSize($size - 1))->validate($this->png(), $this->context),
        );
    }

    public function test_the_size_is_measured_not_taken_from_the_client(): void
    {
        $upload = new Upload(Stream::create(str_repeat('x', 100)), 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');

        $this->assertNotNull((new MaxFileSize(50))->validate($upload, $this->context));
    }

    #[DataProvider('sizes')]
    public function test_the_limit_reads_in_the_unit_a_person_would_use(int $bytes, string $said): void
    {
        $this->assertSame(
            "The file must be {$said} or smaller.",
            (new MaxFileSize($bytes))->validate($this->sized($bytes + 1), $this->context),
        );
    }

    /** @return iterable<string, array{int, string}> */
    public static function sizes(): iterable
    {
        yield 'bytes' => [900, '900 bytes'];
        yield 'kilobytes' => [512 * 1024, '512 KB'];
        yield 'megabytes' => [2 * 1024 * 1024, '2 MB'];
        yield 'fractional megabytes' => [1536 * 1024, '1.5 MB'];
    }

    public function test_an_allowed_type_passes(): void
    {
        $this->assertNull((new MimeType('image/jpeg', 'image/png'))->validate($this->png(), $this->context));
    }

    public function test_the_type_comes_from_the_bytes_not_the_client(): void
    {
        $script = new Upload(Stream::create("<?php system(\$_GET['c']);"), 26, UPLOAD_ERR_OK, 'avatar.png', 'image/png');

        $this->assertSame(
            'The file must be a JPEG, PNG, WebP or GIF image.',
            (new MimeType('image/jpeg', 'image/png', 'image/webp', 'image/gif'))->validate($script, $this->context),
        );
    }

    public function test_a_png_declared_as_something_else_is_still_a_png(): void
    {
        $upload = new Upload(Stream::create(base64_decode(self::PNG)), 67, UPLOAD_ERR_OK, 'notes.txt', 'text/plain');

        $this->assertNull((new MimeType('image/png'))->validate($upload, $this->context));
    }

    public function test_the_type_check_leaves_the_stream_readable_from_the_start(): void
    {
        $upload = $this->png();

        (new MimeType('image/png'))->validate($upload, $this->context);

        $this->assertSame(base64_decode(self::PNG), (string) $upload->getStream());
    }

    public function test_the_message_names_types_it_has_no_word_for_by_their_mime_type(): void
    {
        $this->assertSame(
            'The file must be a PDF or application/x-custom file.',
            (new MimeType('application/pdf', 'application/x-custom'))->validate($this->sized(10), $this->context),
        );
    }

    public function test_a_failed_upload_is_left_to_the_uploaded_file_rule(): void
    {
        // A partial upload has no bytes worth sniffing; saying "wrong type"
        // about it would send someone looking for the wrong problem.
        $this->assertNull((new MimeType('image/png'))->validate($this->failed(UPLOAD_ERR_PARTIAL), $this->context));
        $this->assertNull((new MaxFileSize(1))->validate($this->failed(UPLOAD_ERR_PARTIAL), $this->context));
    }

    public function test_no_file_chosen_is_nothing_to_nullable(): void
    {
        $rules = ['avatar' => [new Nullable, new UploadedFile, new MimeType('image/png')]];

        $result = (new Validator)->validate(['avatar' => $this->failed(UPLOAD_ERR_NO_FILE)], $rules);

        $this->assertTrue($result->passes());
    }

    public function test_no_file_chosen_is_missing_to_required(): void
    {
        $this->assertSame(
            'This field is required.',
            (new Required)->validate($this->failed(UPLOAD_ERR_NO_FILE), $this->context),
        );
    }

    public function test_a_chosen_file_is_present_to_required(): void
    {
        $this->assertNull((new Required)->validate($this->png(), $this->context));
    }

    private function png(): Upload
    {
        $bytes = base64_decode(self::PNG);

        return new Upload(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, 'a.png', 'image/png');
    }

    private function sized(int $bytes): Upload
    {
        return new Upload(Stream::create(str_repeat("\x00\x01", intdiv($bytes, 2) + 1)), $bytes, UPLOAD_ERR_OK, 'a.bin', 'application/octet-stream');
    }

    private function failed(int $error): Upload
    {
        return new Upload(Stream::create(''), 0, $error, '', '');
    }
}
