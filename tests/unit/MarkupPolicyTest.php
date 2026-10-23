<?php
/**
 * Shortcodes and block markup in imported rich text: neutralized by default for
 * new presets, allowed only through an explicit allowlist or trusted setting,
 * and unchanged for presets saved before the setting existed.
 *
 * Uses harmless shortcode callbacks that only record that they ran.
 */

declare(strict_types=1);

use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Tests\TestCase;

final class MarkupPolicyTest extends TestCase
{
    private const CONTENT = '<p>Hello [ppapi_probe] and [ppapi_other id="1"]x[/ppapi_other], footnote [1].</p>'
        . '<!-- wp:latest-posts {"postsToShow":5} /--><!-- wp:paragraph --><p>Block text</p><!-- /wp:paragraph -->';

    /** @var list<string> */
    private array $ran = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        \AcfStub::add_group('group_rich', 'Rich', ['events'], [
            ['key' => 'field_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg'],
            ['key' => 'field_plain', 'name' => 'plain', 'label' => 'Plain', 'type' => 'textarea'],
        ]);
        $this->ran = [];
        $this->register('ppapi_probe', 'ppapi_other');
    }

    private function register(string ...$tags): void
    {
        foreach ($tags as $tag) {
            add_shortcode($tag, function () use ($tag): string {
                $this->ran[] = $tag;
                return 'RAN:' . $tag;
            });
        }
    }

    private function import(array $destination = []): array
    {
        $preset = $this->savePreset([
            'destination' => $destination,
            'mappings'    => [
                $this->row('event.title', 'core', 'post_title'),
                $this->row('body', 'core', 'post_content'),
                $this->row('body', 'core', 'post_excerpt'),
                $this->row('body', 'acf', 'field_body'),
                $this->row('note', 'meta', 'source_note'),
            ],
        ]);
        $this->queueJson(['ok' => true, 'data' => [['id' => 'r1', 'event' => ['title' => 'One'], 'body' => self::CONTENT, 'note' => '[ppapi_probe] stays data']]]);
        $state = $this->runPreset($preset['id']);
        $this->assertSame('completed', $state['status'], wp_json_encode($state['issues']));

        return $this->posts()[0];
    }

    public function testNewPresetsNeutralizeShortcodesAndBlocksByDefault(): void
    {
        $this->assertSame(MarkupPolicy::NEUTRALIZE, \PostPressAPI\Presets\PresetSchema::defaults()['destination']['markup']);
        $post = $this->import();

        foreach ([$post['post_content'], $post['post_excerpt'], $this->meta($post['ID'], 'body')] as $html) {
            $this->assertSame($html, do_shortcode($html));
            $this->assertNotContains('<!-- wp:', $html, 'no block can render dynamically');
            $this->assertContains('<p>Block text</p>', $html, 'static block HTML is kept');
            $this->assertSame(
                '<p>Hello [ppapi_probe] and [ppapi_other id="1"]x[/ppapi_other], footnote [1].</p><p>Block text</p>',
                html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'shortcodes still read as text'
            );
        }
        $this->assertSame([], $this->ran, 'no shortcode ran');
        $this->assertSame('[ppapi_probe] stays data', $this->meta($post['ID'], 'source_note'), 'plain meta is stored as data, unchanged');
    }

    public function testAllowlistKeepsOnlyListedShortcodes(): void
    {
        $post = $this->import(['markup' => MarkupPolicy::ALLOWLIST, 'shortcodes' => ['ppapi_probe']]);

        $rendered = do_shortcode($post['post_content']);
        $this->assertContains('RAN:ppapi_probe', $rendered);
        $this->assertNotContains('RAN:ppapi_other', $rendered);
        $this->assertSame(['ppapi_probe'], $this->ran);
        $this->assertNotContains('<!-- wp:', $post['post_content'], 'the allowlist covers shortcodes only, not blocks');
    }

    /** Confirmed bypass: WordPress accepts quotes (and other punctuation) in shortcode names. */
    public function testUnusualShortcodeNamesRunOnlyWhenListedInFull(): void
    {
        $this->register('"evil', 'ppapi_probe"evil', "it's", 'ppapi_probe.x');
        $html = '<p>["evil] [ppapi_probe"evil] [it\'s] [ppapi_probe.x] [/ppapi_probe"evil] [ppapi_probe]</p>';

        $neutralized = (new MarkupPolicy())->apply($html);
        $this->assertNotContains('[', $neutralized, 'every bracket is neutralized');
        do_shortcode($neutralized);
        $this->assertSame([], $this->ran);

        $listed = (new MarkupPolicy(MarkupPolicy::ALLOWLIST, ['ppapi_probe']))->apply($html);
        $this->assertSame('<p>&#91;"evil] &#91;ppapi_probe"evil] &#91;it\'s] &#91;ppapi_probe.x] &#91;/ppapi_probe"evil] [ppapi_probe]</p>', $listed);
        $this->assertSame('<p>&#91;"evil] &#91;ppapi_probe"evil] &#91;it\'s] &#91;ppapi_probe.x] &#91;/ppapi_probe"evil] RAN:ppapi_probe</p>', do_shortcode($listed));
        $this->assertSame(['ppapi_probe'], $this->ran, 'only the complete listed name runs');
    }

    public function testAllowlistKeepsEscapesAndClosingTagsOfListedShortcodesOnly(): void
    {
        $policy = new MarkupPolicy(MarkupPolicy::ALLOWLIST, ['ppapi_probe']);
        $cases  = [
            '[[ppapi_probe]]'               => ['[[ppapi_probe]]', '[ppapi_probe]'],
            '[ppapi_probe]x[/ppapi_probe]'  => ['[ppapi_probe]x[/ppapi_probe]', 'RAN:ppapi_probe'],
            '[[ppapi_other]]'               => ['&#91;&#91;ppapi_other]]', '&#91;&#91;ppapi_other]]'],
            '[ppapi_probe]x[/ppapi_other]'  => ['[ppapi_probe]x&#91;/ppapi_other]', 'RAN:ppapi_probex&#91;/ppapi_other]'],
            'a[] [ [= [/ [[ [<b>x</b>]'     => ['a&#91;] &#91; &#91;= &#91;/ &#91;&#91; &#91;<b>x</b>]', 'a&#91;] &#91; &#91;= &#91;/ &#91;&#91; &#91;<b>x</b>]'],
        ];

        foreach ($cases as $html => [$stored, $rendered]) {
            $this->assertSame($stored, $policy->apply($html), $html);
            $this->assertSame($stored, $policy->apply($stored), 'idempotent: ' . $html);
            $this->assertSame($rendered, do_shortcode($stored), 'rendered: ' . $html);
        }
        $this->assertSame(['ppapi_probe', 'ppapi_probe'], $this->ran);
    }

    public function testTrustedSourceKeepsEverything(): void
    {
        $post = $this->import(['markup' => MarkupPolicy::TRUSTED]);

        $this->assertSame(wp_kses_post(self::CONTENT), $post['post_content']);
        do_shortcode($post['post_content']);
        $this->assertSame(['ppapi_probe', 'ppapi_other'], $this->ran);
    }

    public function testPresetsSavedBeforeTheSettingKeepTheirBehaviorAndPostsAreNotRewritten(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('event.title', 'core', 'post_title'), $this->row('body', 'core', 'post_content')]]);
        // Stored by version 1.0.5: no markup key.
        $stored = get_option(PresetRepository::PRESET_PREFIX . $preset['id']);
        unset($stored['destination']['markup'], $stored['destination']['shortcodes']);
        update_option(PresetRepository::PRESET_PREFIX . $preset['id'], $stored, false);

        $loaded = $this->services->presets()->find($preset['id']);
        $this->assertSame(MarkupPolicy::TRUSTED, $loaded['destination']['markup'], 'explicit compatibility policy');

        $payload = ['ok' => true, 'data' => [['id' => 'r1', 'event' => ['title' => 'One'], 'body' => self::CONTENT]]];
        $this->queueJson($payload);
        $this->runPreset($preset['id']);
        $this->queueJson($payload);
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['unchanged'], 'upgrading never rewrites existing posts');
        $this->assertSame(wp_kses_post(self::CONTENT), $this->posts()[0]['post_content']);

        $validation = $this->services->preset_validator()->validate($loaded, $loaded, ['action' => 'keep', 'value' => '']);
        $this->assertContains('may run any shortcode or block', implode(' ', $validation['warnings']));
    }

    public function testAllowlistValidation(): void
    {
        $validator = $this->services->preset_validator();
        $base      = $this->savePreset();

        $empty = $validator->validate(array_replace_recursive($base, ['destination' => ['markup' => 'allowlist', 'shortcodes' => '']]), $base, ['action' => 'keep', 'value' => '']);
        $this->assertArrayHasKey('destination.markup', $empty['errors']);

        $invalid = $validator->validate(array_replace_recursive($base, ['destination' => ['markup' => 'allowlist', 'shortcodes' => 'gallery, bad<tag>']]), $base, ['action' => 'keep', 'value' => '']);
        $this->assertContains('bad<tag>', implode(' ', $invalid['errors']['destination.markup']));

        $valid = $validator->validate(array_replace_recursive($base, ['destination' => ['markup' => 'allowlist', 'shortcodes' => 'gallery, [caption]']]), $base, ['action' => 'keep', 'value' => '']);
        $this->assertSame(false, isset($valid['errors']['destination.markup']));
        $this->assertSame(['gallery', 'caption'], $valid['preset']['destination']['shortcodes']);

        $unknown = $validator->validate(array_replace_recursive($base, ['destination' => ['markup' => 'anything']]), $base, ['action' => 'keep', 'value' => '']);
        $this->assertSame(MarkupPolicy::NEUTRALIZE, $unknown['preset']['destination']['markup'], 'unknown values fall back to the safe default');
    }

    public function testPolicyIsIdempotentAndLeavesOrdinaryHtmlAlone(): void
    {
        $policy = new MarkupPolicy();
        $once   = $policy->apply(self::CONTENT);
        $this->assertSame($once, $policy->apply($once), 'stable output keeps change detection stable');
        $this->assertSame('<p>No brackets, <a href="https://example.com/?a=1&amp;b=2">link</a></p><!-- note -->', $policy->apply('<p>No brackets, <a href="https://example.com/?a=1&amp;b=2">link</a></p><!-- note -->'));
        $this->assertSame('[ppapi_probe]', (new MarkupPolicy(MarkupPolicy::TRUSTED))->apply('[ppapi_probe]'));
    }
}
