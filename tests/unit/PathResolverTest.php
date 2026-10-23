<?php
/**
 * Path resolution: nested keys, indices, wildcards, quoting, and the
 * missing / null / false / 0 / "" / [] distinctions.
 */

declare(strict_types=1);

use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Mapping\PathResult;
use PostPressAPI\Mapping\PathSyntaxError;
use PostPressAPI\Mapping\SourcePathDiscovery;
use PostPressAPI\Tests\TestCase;

final class PathResolverTest extends TestCase
{
    private PathResolver $paths;
    private array $record;

    public function setUp(): void
    {
        parent::setUp();
        $this->paths  = new PathResolver();
        $this->record = $this->fixture()['data'][0];
    }

    public function testNestedObjectPath(): void
    {
        $result = $this->paths->resolve($this->record, 'event.title');
        $this->assertTrue($result->is_present());
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', $result->value);
    }

    public function testNumericAndBracketIndicesAreEquivalent(): void
    {
        $this->assertSame('2026-11-05', $this->paths->resolve($this->record, 'sessions.0.date')->value);
        $this->assertSame('2026-11-05', $this->paths->resolve($this->record, 'sessions[0].date')->value);
        $this->assertSame('2026-11-05', $this->paths->resolve($this->record, '$.sessions[0].date')->value);
    }

    public function testLastItemUsesTheCurrentListLengthForAnyKey(): void
    {
        foreach (['items', 'products', 'unrelated_api_key'] as $key) {
            foreach ([['only'], ['first', 'second'], ['first', 'second', 'third']] as $items) {
                $result = $this->paths->resolve([$key => $items], $key . '[-1]');
                $this->assertTrue($result->is_present());
                $this->assertFalse($result->multiple);
                $this->assertSame($items[count($items) - 1], $result->value);
            }
        }
        $this->assertSame('2025-01-01', $this->paths->resolve(['dates' => ['2026-01-01', '2025-01-01']], 'dates[-1]')->value, 'last means supplied order, not latest date');
    }

    public function testLastItemSupportsRootListsAndNestedTraversal(): void
    {
        $groups = [
            ['members' => [['email' => 'first@example.test']]],
            ['members' => [['email' => 'middle@example.test'], ['email' => 'last@example.test']]],
        ];
        $this->assertSame($groups[1], $this->paths->resolve($groups, '[-1]')->value);
        $this->assertSame('last@example.test', $this->paths->resolve($groups, '$[-1].members[-1].email')->value);
        $this->assertSame('last@example.test', $this->paths->resolve(['groups' => $groups], '$.groups[-1].members[-1].email')->value);
        $this->assertSame(3, $this->paths->resolve([[1], [2, 3]], '[-1][-1]')->value);
    }

    public function testLastItemPreservesNullAndEmptyValueSemantics(): void
    {
        foreach ([false, 0, '0', '', []] as $value) {
            $result = $this->paths->resolve(['items' => ['first', $value]], 'items[-1]');
            $this->assertTrue($result->is_present());
            $this->assertSame($value, $result->value);
        }
        $this->assertTrue($this->paths->resolve(['items' => ['first', null]], 'items[-1]')->is_null());
        $this->assertTrue($this->paths->resolve(['items' => [null]], 'items[-1].name')->is_null());
        $this->assertTrue($this->paths->resolve(['items' => null], 'items[-1].name')->is_null());
        $this->assertTrue($this->paths->resolve(null, '[-1]')->is_null());
        $this->assertTrue($this->paths->resolve([], '[-1]')->is_missing());
        $this->assertTrue($this->paths->resolve(['items' => []], 'items[-1].name')->is_missing());
        $this->assertTrue($this->paths->resolve([], 'items[-1]')->is_missing());
        $this->assertTrue($this->paths->resolve(['items' => [['other' => 1]]], 'items[-1].name')->is_missing());
    }

    public function testLastItemRejectsObjectsAndScalarsWithoutChangingLiteralKeys(): void
    {
        foreach ([['a' => 'first', 'b' => 'last'], [1 => 'one', 3 => 'three'], [-1 => 'literal'], 'text', 42, false] as $value) {
            $this->assertTrue($this->paths->resolve(['items' => $value], 'items[-1]')->is_missing());
        }
        $object = json_decode('{"-1":"literal","other":"final"}', true);
        foreach (['["-1"]', "['-1']", '-1'] as $path) {
            $this->assertSame('literal', $this->paths->resolve($object, $path)->value);
        }
        $this->assertSame('last', $this->paths->resolve(['a.b' => ['first', 'last']], '["a.b"][-1]')->value);
    }

    public function testLastItemWorksBeforeAndAfterWildcards(): void
    {
        $data = ['groups' => [
            ['members' => [['name' => 'first'], ['name' => 'second']]],
            ['members' => []],
            ['members' => [['name' => 'ignored'], null]],
            ['members' => [['name' => 'third'], ['name' => 'fourth']]],
        ]];
        $result = $this->paths->resolve($data, 'groups[*].members[-1].name');
        $this->assertTrue($result->multiple);
        $this->assertSame(['second', 'fourth'], $result->value, 'existing wildcard rules skip missing and null matches');
        $this->assertSame(['third', 'fourth'], $this->paths->resolve($data, 'groups[-1].members[*].name')->value);
        $this->assertSame(['second', 'fourth'], $this->paths->resolve($data, 'groups.*.members[-1].name')->value);
        $this->assertFalse($this->paths->has_wildcard('groups[-1].members[-1]'));
        $this->assertTrue($this->paths->has_wildcard('groups[*].members[-1]'));
    }

    public function testWildcardsCollectLists(): void
    {
        $record = ['sessions' => [['date' => 'a', 'time' => '1'], ['date' => 'b'], ['time' => '3'], ['date' => null]]];

        $dates = $this->paths->resolve($record, 'sessions[*].date');
        $this->assertTrue($dates->multiple);
        $this->assertSame(['a', 'b'], $dates->value, 'missing and null items are left out');

        $this->assertSame(['Nov 5, 2026 at 12:00 PM Eastern'], $this->paths->resolve($this->record, 'dates_text[*]')->value);
        $this->assertSame(['a', 'b'], $this->paths->resolve($record, 'sessions.*.date')->value);
    }

    public function testWildcardOverEmptyListIsPresentAndEmpty(): void
    {
        $result = $this->paths->resolve(['items' => []], 'items[*].name');
        $this->assertTrue($result->is_present());
        $this->assertSame([], $result->value);
    }

    public function testWildcardWithNoMatchingItemsIsMissing(): void
    {
        $this->assertTrue($this->paths->resolve(['items' => [['a' => 1]]], 'items[*].name')->is_missing());
        $this->assertTrue($this->paths->resolve([], 'items[*]')->is_missing());
    }

    public function testNestedWildcardsFlatten(): void
    {
        $data = ['a' => [['b' => [1, 2]], ['b' => [3]]]];
        $this->assertSame([1, 2, 3], $this->paths->resolve($data, 'a[*].b[*]')->value);
    }

    public function testMissingNullFalseZeroEmptyStringAndEmptyArrayStayDistinct(): void
    {
        $data = ['n' => null, 'f' => false, 'z' => 0, 's' => '', 'a' => [], 'zs' => '0'];

        $this->assertSame(PathResult::MISSING, $this->paths->resolve($data, 'nope')->state);
        $this->assertSame(PathResult::NULL, $this->paths->resolve($data, 'n')->state);

        foreach (['f' => false, 'z' => 0, 's' => '', 'a' => [], 'zs' => '0'] as $key => $expected) {
            $result = $this->paths->resolve($data, $key);
            $this->assertTrue($result->is_present(), $key . ' is present');
            $this->assertSame($expected, $result->value, $key);
        }
    }

    public function testNullParentResolvesAsNullAndScalarParentAsMissing(): void
    {
        $null = $this->paths->resolve($this->record, 'location.city');
        $this->assertTrue($null->is_null(), 'location is null in the fixture');
        $this->assertContains('location', (string) $null->note);

        $this->assertTrue($this->paths->resolve($this->record, 'event.title.length')->is_missing());
        $this->assertTrue($this->paths->resolve($this->record, 'image')->is_null());
    }

    public function testQuotedKeysAddressLiteralDotsAndBrackets(): void
    {
        $data = ['a.b' => ['c' => 1], 'x[0]' => 'bracket', '$ref' => 'dollar', '*' => 'star', 'q"k' => 'quote'];

        $this->assertSame(1, $this->paths->resolve($data, '["a.b"].c')->value);
        $this->assertSame('bracket', $this->paths->resolve($data, "['x[0]']")->value);
        $this->assertSame('dollar', $this->paths->resolve($data, '["$ref"]')->value);
        $this->assertSame('star', $this->paths->resolve($data, '["*"]')->value);
        $this->assertSame('quote', $this->paths->resolve($data, '["q\\"k"]')->value);
    }

    public function testRootPathReturnsWholeContext(): void
    {
        $this->assertSame($this->record, $this->paths->resolve($this->record, '$')->value);
        $this->assertSame(['x'], $this->paths->resolve(['x'], '$')->value);
    }

    public function testBigIntegerIdsStayStrings(): void
    {
        $data = json_decode('{"id": 123456789012345678901234567890}', true, 512, JSON_BIGINT_AS_STRING);
        $this->assertSame('123456789012345678901234567890', $this->paths->resolve($data, 'id')->value);
    }

    public function testInvalidPathsAreRejected(): void
    {
        foreach (['', 'a..b', '.a', 'a.', 'a[', 'a[-2]', 'a[-0]', 'a[-01]', 'a[-10]', 'a[-1.0]', 'a[-1:]', 'a[-1', 'a[-1]b', 'a[last()]', 'a[length-1]', 'a[0:2]', 'a[x]', 'a]', '$x', 'a.[0]', 'a[0]b', '["unterminated]'] as $path) {
            $this->assertThrows(PathSyntaxError::class, fn () => $this->paths->parse($path));
            $this->assertNotNull($this->paths->validate($path), 'validate() reports ' . var_export($path, true));
        }
        $this->assertNull($this->paths->validate('event.title'));
        $this->assertNull($this->paths->validate('groups[-1].members[-1].email'));
        $this->assertNull($this->paths->validate('[-1]'));
    }

    public function testFormatProducesParseablePaths(): void
    {
        foreach (['event.title', 'sessions[0].date', 'sessions[*].date', 'products[-1].name', 'groups[-1].members[-1].email', 'groups[*].members[-1]', '[-1]', '["-1"]', '["a.b"][-1].c', '$'] as $path) {
            $tokens = $this->paths->parse($path);
            $this->assertSame($tokens, $this->paths->parse($this->paths->format($tokens)), $path);
        }
    }

    public function testDiscoveryListsRecordPathsWithTypes(): void
    {
        $paths = (new SourcePathDiscovery($this->paths))->discover_records($this->fixture()['data']);
        $index = [];
        foreach ($paths as $item) {
            $index[$item['path']] = $item['type'];
        }

        $this->assertSame('string', $index['event.title'] ?? null);
        $this->assertSame('boolean', $index['event.virtual'] ?? null);
        $this->assertSame('null', $index['location'] ?? null);
        $this->assertSame('string', $index['sessions[*].date'] ?? null);
        $this->assertSame('string', $index['sessions[0].time'] ?? null);
        $this->assertSame('string', $index['sessions[-1].time'] ?? null);
        $this->assertSame('string', $index['dates_text[*]'] ?? null);
        $this->assertSame('string', $index['dates_text[-1]'] ?? null);
        $this->assertArrayHasKey('sessions', $index);
    }

    public function testDiscoverySuggestsActualLastItemsAndPreservesLiteralNegativeKeys(): void
    {
        $record = [
            'products' => [1, 2, 3, 4, 5, ['name' => 'final product']],
            'groups' => [['members' => [['email' => 'last@example.test']]]],
            'nullable' => ['first', null],
            'object' => [-1 => 'literal'],
            'empty' => [],
        ];
        $discovery = new SourcePathDiscovery($this->paths);
        $index = array_column($discovery->discover_records([$record]), null, 'path');
        $this->assertSame('final product', $index['products[-1].name']['sample']);
        $this->assertSame('last@example.test', $index['groups[-1].members[-1].email']['sample']);
        $this->assertSame('null', $index['nullable[-1]']['type']);
        $this->assertSame('literal', $index['object["-1"]']['sample']);
        $this->assertFalse(isset($index['object[-1]']));
        $this->assertFalse(isset($index['empty[-1]']));
        $this->assertSame('literal', $this->paths->resolve($record, 'object["-1"]')->value);

        $root = array_column($discovery->discover_records([['first', 'last']]), null, 'path');
        $this->assertSame('last', $root['[-1]']['sample']);
        $this->assertTrue(count($discovery->discover_records([$record], 1, 5)) <= 5, 'discovery still respects its path limit');
    }
}
