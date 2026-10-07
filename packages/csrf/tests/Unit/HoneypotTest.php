<?php

declare(strict_types=1);

namespace Hydra\Csrf\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Csrf\Honeypot;
use Hydra\Csrf\Rules\SubmittedAfter;
use Hydra\Validation\Rules\Honeypot as HoneypotRule;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Honeypot::class)]
final class HoneypotTest extends TestCase
{
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
    }

    public function test_the_trap_is_hidden_from_eyes_keyboards_screen_readers_and_autofill(): void
    {
        $xpath = $this->parse($this->honeypot()->markup());

        $wrapper = $this->one($xpath, '//div');
        $this->assertSame('form-trap', $wrapper->getAttribute('class'));
        $this->assertSame('true', $wrapper->getAttribute('aria-hidden'));

        $trap = $this->one($xpath, '//div//input');
        $this->assertSame('text', $trap->getAttribute('type'));
        $this->assertSame('website', $trap->getAttribute('name'));
        $this->assertSame('', $trap->getAttribute('value'));
        $this->assertSame('-1', $trap->getAttribute('tabindex'));
        $this->assertSame('off', $trap->getAttribute('autocomplete'));
        // No inline style: a style-src policy without 'unsafe-inline' drops
        // it, and the trap would show.
        $this->assertSame(0, $xpath->query('//*[@style]')?->length);
    }

    public function test_the_start_time_is_accepted_once_the_minimum_has_passed(): void
    {
        $honeypot = $this->honeypot();
        $input = $this->submitted($honeypot->markup());

        $this->assertTrue((new Validator)->validate($input, $honeypot->rules())->fails());

        $this->clock->advance('+3 seconds');

        $this->assertTrue((new Validator)->validate($input, $honeypot->rules())->passes());
    }

    public function test_each_render_carries_its_own_start_time(): void
    {
        $honeypot = $this->honeypot();
        $first = $this->submitted($honeypot->markup())[Honeypot::STARTED];

        $this->clock->advance('+1 minute');

        $this->assertNotSame($first, $this->submitted($honeypot->markup())[Honeypot::STARTED]);
    }

    public function test_the_rules_are_keyed_by_the_trap_and_the_start_time(): void
    {
        $rules = $this->honeypot(field: 'homepage')->rules();

        $this->assertSame(['homepage', Honeypot::STARTED], array_keys($rules));
        $this->assertInstanceOf(HoneypotRule::class, $rules['homepage'][0]);
        $this->assertInstanceOf(SubmittedAfter::class, $rules[Honeypot::STARTED][0]);
    }

    public function test_the_limits_reach_the_timer(): void
    {
        $honeypot = $this->honeypot(minSeconds: 10, maxAge: 20);
        $input = $this->submitted($honeypot->markup());

        $this->clock->advance('+9 seconds');
        $this->assertNotNull($honeypot->error((new Validator)->validate($input, $honeypot->rules())));

        $this->clock->advance('+12 seconds');
        $this->assertSame(
            'This form has expired. Please send it again.',
            $honeypot->error((new Validator)->validate($input, $honeypot->rules())),
        );
    }

    public function test_a_custom_field_name_is_escaped(): void
    {
        $xpath = $this->parse($this->honeypot(field: 'a"b<c')->markup());

        $this->assertSame('a"b<c', $this->one($xpath, '//div//input')->getAttribute('name'));
    }

    public function test_error_names_the_trap_before_the_timer(): void
    {
        // Posted at once with the trap filled: both fail, and the trap's
        // message is the one shown.
        $honeypot = $this->honeypot();
        $input = [...$this->submitted($honeypot->markup()), 'website' => 'spam'];

        $result = (new Validator)->validate($input, $honeypot->rules());

        $this->assertSame('Your message could not be sent.', $honeypot->error($result));
    }

    public function test_error_falls_back_to_the_timer_then_to_nothing(): void
    {
        $honeypot = $this->honeypot();
        $input = $this->submitted($honeypot->markup());

        $this->assertSame(
            'That was quick. Please check your message and send it again.',
            $honeypot->error((new Validator)->validate($input, $honeypot->rules())),
        );

        $this->clock->advance('+3 seconds');

        $this->assertNull($honeypot->error((new Validator)->validate($input, $honeypot->rules())));
    }

    public function test_error_ignores_the_forms_other_fields(): void
    {
        $honeypot = $this->honeypot();
        $input = [...$this->submitted($honeypot->markup()), 'email' => ''];
        $this->clock->advance('+3 seconds');

        $result = (new Validator)->validate($input, [...$honeypot->rules(), 'email' => [new Required]]);

        $this->assertTrue($result->fails());
        $this->assertNull($honeypot->error($result));
    }

    public function test_the_trap_cannot_share_the_start_times_name_or_be_blank(): void
    {
        foreach (['', Honeypot::STARTED] as $field) {
            try {
                $this->honeypot(field: $field);
                $this->fail("'{$field}' was accepted as the trap's name.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('trap', $e->getMessage());
            }
        }
    }

    private function honeypot(string $field = 'website', int $minSeconds = 3, int $maxAge = 86_400): Honeypot
    {
        return new Honeypot(Signer::fromHex(self::KEY_HEX), $this->clock, $field, $minSeconds, $maxAge);
    }

    /**
     * What a browser would post: every input's name and value as printed.
     *
     * @return array<string, string>
     */
    private function submitted(string $markup): array
    {
        $input = [];

        foreach ($this->parse($markup)->query('//input') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $input[$node->getAttribute('name')] = $node->getAttribute('value');
            }
        }

        return $input;
    }

    private function parse(string $markup): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>', LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    private function one(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $query);
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }
}
