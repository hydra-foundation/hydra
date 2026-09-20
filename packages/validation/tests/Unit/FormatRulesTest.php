<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\Alpha;
use Hydra\Validation\Rules\AlphaNumeric;
use Hydra\Validation\Rules\Email;
use Hydra\Validation\Rules\IpAddress;
use Hydra\Validation\Rules\Json;
use Hydra\Validation\Rules\Slug;
use Hydra\Validation\Rules\Url;
use Hydra\Validation\Rules\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The shapes a value can be written in. All of these take a string and only a
 * string: a value of another type fails rather than being stringified into
 * something that happens to match.
 */
#[CoversClass(Alpha::class)]
#[CoversClass(AlphaNumeric::class)]
#[CoversClass(Email::class)]
#[CoversClass(IpAddress::class)]
#[CoversClass(Json::class)]
#[CoversClass(Slug::class)]
#[CoversClass(Url::class)]
#[CoversClass(Uuid::class)]
final class FormatRulesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context([], 'field');
    }

    public function test_email_accepts_an_address_and_refuses_a_fragment(): void
    {
        $rule = new Email;

        $this->assertNull($rule->validate('ada@example.com', $this->context));
        $this->assertNull($rule->validate('ada+tag@sub.example.co.uk', $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate('ada@', $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate('ada example.com', $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate('', $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate(null, $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate(['a@b.com'], $this->context));
    }

    public function test_email_refuses_an_address_longer_than_a_path_can_carry(): void
    {
        $long = str_repeat('a', 250) . '@example.com';

        $this->assertSame('Enter a valid email address.', (new Email)->validate($long, $this->context));
    }

    public function test_url_accepts_http_and_https_by_default(): void
    {
        $rule = new Url;

        $this->assertNull($rule->validate('https://example.com/path?q=1', $this->context));
        $this->assertNull($rule->validate('http://example.com', $this->context));
        $this->assertSame('Enter a valid URL.', $rule->validate('example.com', $this->context));
        $this->assertSame('Enter a valid URL.', $rule->validate('', $this->context));
    }

    public function test_url_refuses_the_schemes_that_execute(): void
    {
        // PHP's URL filter accepts these, and a stored link is rendered into
        // an href later. The allowlist is the whole point of the rule.
        $rule = new Url;

        $this->assertSame('Enter a valid URL.', $rule->validate('javascript://comment%0aalert(1)', $this->context));
        $this->assertSame('Enter a valid URL.', $rule->validate('data://text/plain;base64,SSBsb3ZlIFBIUAo=', $this->context));
    }

    public function test_url_takes_its_own_scheme_list(): void
    {
        $rule = new Url(['ftp']);

        $this->assertNull($rule->validate('ftp://files.example.com/x', $this->context));
        $this->assertSame('Enter a valid URL.', $rule->validate('https://example.com', $this->context));
    }

    public function test_url_refuses_an_empty_scheme_list_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Url([]);
    }

    public function test_ip_address_accepts_both_families_unless_told_otherwise(): void
    {
        $any = new IpAddress;

        $this->assertNull($any->validate('192.168.0.1', $this->context));
        $this->assertNull($any->validate('2001:db8::1', $this->context));
        $this->assertSame('Enter a valid IP address.', $any->validate('999.1.1.1', $this->context));

        $this->assertNull((new IpAddress(4))->validate('192.168.0.1', $this->context));
        $this->assertNotNull((new IpAddress(4))->validate('2001:db8::1', $this->context));
        $this->assertNull((new IpAddress(6))->validate('2001:db8::1', $this->context));
        $this->assertNotNull((new IpAddress(6))->validate('192.168.0.1', $this->context));
    }

    public function test_ip_address_refuses_a_version_it_does_not_know(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IpAddress(5);
    }

    public function test_uuid_accepts_the_canonical_form_only(): void
    {
        $rule = new Uuid;

        $this->assertNull($rule->validate('9f1b7c4e-3a2d-4f6b-9c8e-1d2a3b4c5d6e', $this->context));
        $this->assertNull($rule->validate('9F1B7C4E-3A2D-4F6B-9C8E-1D2A3B4C5D6E', $this->context));
        $this->assertSame('Enter a valid UUID.', $rule->validate('9f1b7c4e3a2d4f6b9c8e1d2a3b4c5d6e', $this->context));
        $this->assertSame('Enter a valid UUID.', $rule->validate('not-a-uuid', $this->context));
    }

    public function test_uuid_refuses_the_nil_uuid_and_a_wrong_version(): void
    {
        // The nil UUID is the absence of an id, not an id.
        $this->assertNotNull((new Uuid)->validate('00000000-0000-0000-0000-000000000000', $this->context));
        $this->assertNotNull((new Uuid(4))->validate('9f1b7c4e-3a2d-7f6b-9c8e-1d2a3b4c5d6e', $this->context));
        $this->assertNull((new Uuid(7))->validate('9f1b7c4e-3a2d-7f6b-9c8e-1d2a3b4c5d6e', $this->context));
    }

    public function test_uuid_refuses_a_version_outside_the_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Uuid(9);
    }

    public function test_slug_accepts_single_hyphen_separated_groups(): void
    {
        $rule = new Slug;

        $this->assertNull($rule->validate('hello-world', $this->context));
        $this->assertNull($rule->validate('php-8-2', $this->context));
        $this->assertSame('Use lowercase letters, numbers and hyphens.', $rule->validate('-leading', $this->context));
        $this->assertSame('Use lowercase letters, numbers and hyphens.', $rule->validate('trailing-', $this->context));
        $this->assertSame('Use lowercase letters, numbers and hyphens.', $rule->validate('double--hyphen', $this->context));
        $this->assertSame('Use lowercase letters, numbers and hyphens.', $rule->validate('Hello World', $this->context));
        $this->assertSame('Use lowercase letters, numbers and hyphens.', $rule->validate('', $this->context));
    }

    public function test_alpha_and_alphanumeric_are_ascii_only(): void
    {
        $this->assertNull((new Alpha)->validate('Ada', $this->context));
        $this->assertSame('Use letters only.', (new Alpha)->validate('Ada2', $this->context));
        $this->assertSame('Use letters only.', (new Alpha)->validate("\u{e5}\u{e4}\u{f6}", $this->context));

        $this->assertNull((new AlphaNumeric)->validate('Ada2', $this->context));
        $this->assertSame('Use letters and numbers only.', (new AlphaNumeric)->validate('ada_2', $this->context));
        $this->assertNull((new AlphaNumeric(allowDashes: true))->validate('ada_2-b', $this->context));
    }

    public function test_json_accepts_well_formed_documents_only(): void
    {
        $rule = new Json;

        $this->assertNull($rule->validate('{"a":1}', $this->context));
        $this->assertNull($rule->validate('[1,2,3]', $this->context));
        $this->assertNull($rule->validate('"a string"', $this->context));
        $this->assertSame('Must be valid JSON.', $rule->validate('{"a":}', $this->context));
        $this->assertSame('Must be valid JSON.', $rule->validate('   ', $this->context));
        $this->assertSame('Must be valid JSON.', $rule->validate(['a' => 1], $this->context));
    }

    public function test_json_enforces_its_depth_limit(): void
    {
        $this->assertSame('Must be valid JSON.', (new Json(2))->validate('[[[1]]]', $this->context));
        $this->assertNull((new Json(2))->validate('[1]', $this->context));
    }

    public function test_json_refuses_a_depth_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Json(0);
    }

    public function test_every_format_rule_refuses_a_value_that_is_not_a_string(): void
    {
        // `field[]=x` arrives as an array and an object would be a fatal under
        // a cast; wrong-shaped input fails cleanly instead.
        $rules = [new Alpha, new AlphaNumeric, new Email, new IpAddress, new Json, new Slug, new Url, new Uuid];

        foreach ($rules as $rule) {
            foreach ([['x'], new \stdClass, true, 1, null] as $value) {
                $this->assertNotNull($rule->validate($value, $this->context), $rule::class);
            }
        }
    }

    public function test_alpha_anchors_at_both_ends(): void
    {
        $this->assertSame('Use letters only.', (new Alpha)->validate('1abc', $this->context));
    }

    public function test_an_address_may_be_as_long_as_the_rfc_allows_and_no_longer(): void
    {
        $rule = new Email;
        $label = str_repeat('b', 61);
        $limit = str_repeat('a', 64) . "@{$label}.{$label}.{$label}.com";
        $over = str_repeat('a', 64) . '@' . str_repeat('b', 62) . ".{$label}.{$label}.com";

        $this->assertSame(254, strlen($limit));
        $this->assertSame(255, strlen($over));
        $this->assertNull($rule->validate($limit, $this->context));
        $this->assertSame('Enter a valid email address.', $rule->validate($over, $this->context));
    }

    public function test_json_may_be_held_to_a_single_level(): void
    {
        $rule = new Json(1);

        $this->assertNull($rule->validate('1', $this->context));
        $this->assertSame('Must be valid JSON.', $rule->validate('{"a":1}', $this->context));
    }

    public function test_json_refuses_whitespace(): void
    {
        $this->assertSame('Must be valid JSON.', (new Json)->validate("  \n ", $this->context));
    }

    public function test_a_url_scheme_is_compared_without_case(): void
    {
        $this->assertNull((new Url)->validate('HTTPS://example.com', $this->context));
        $this->assertNull((new Url(['FTP']))->validate('ftp://example.com', $this->context));
    }

    public function test_a_url_the_filter_refuses_is_not_rescued_by_its_scheme(): void
    {
        $this->assertSame('Enter a valid URL.', (new Url)->validate('http://exa mple.com', $this->context));
    }

    public function test_uuid_accepts_the_lowest_and_the_highest_version(): void
    {
        $this->assertNull((new Uuid(1))->validate('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $this->context));
        $this->assertNull((new Uuid(8))->validate('017f22e2-79b0-8cc3-98c4-dc0c0c07398f', $this->context));
    }

    public function test_json_is_read_no_deeper_than_its_limit(): void
    {
        $rule = new Json;
        $within = str_repeat('[', 511) . '1' . str_repeat(']', 511);
        $past = str_repeat('[', 512) . '1' . str_repeat(']', 512);

        $this->assertNull($rule->validate($within, $this->context));
        $this->assertSame('Must be valid JSON.', $rule->validate($past, $this->context));
    }
}
