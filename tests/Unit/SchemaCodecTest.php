<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;

/**
 * How JCB stores a value, and how it reads back.
 *
 * `encode()` and `decode()` are the two halves of one contract and the only
 * thing holding the two directions together: a row read out of JCB's tables
 * has to look like the same row read out of a blueprint repository, or an
 * export from the database and an export from disk describe the same models
 * differently and nothing downstream can tell which is right.
 *
 * So what is pinned here is mostly that one undoes the other.
 */
final class SchemaCodecTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schema = new Schema([
            'joomlaColumns' => ['id', 'published'],
            'entities' => [
                'field' => [
                    'name'       => 'field',
                    'portable'   => true,
                    'transport'  => ['guidField' => 'guid', 'children' => []],
                    'properties' => [
                        'guid'     => ['kind' => 'property', 'portable' => true, 'store' => null],
                        'name'     => ['kind' => 'property', 'portable' => true, 'store' => null],
                        'php_code' => ['kind' => 'property', 'portable' => true, 'store' => 'base64'],
                        'settings' => ['kind' => 'property', 'portable' => true, 'store' => 'json'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function values(): array
    {
        return [
            'plain text'              => ['name', 'Greeting'],
            'text that looks numeric' => ['name', '7'],
            'php source'              => ['php_code', "<?php\n\treturn \$this->x > 1 && \$y;\n"],
            'php source with unicode' => ['php_code', "// éàü — ok\nreturn true;"],
            'a subform'               => ['settings', ['rows' => [['a' => '1'], ['b' => '2']]]],
            'a nested structure'      => ['settings', ['a' => ['b' => ['c' => 'd']]]],
        ];
    }

    /**
     * The property the two directions exist for.
     */
    #[DataProvider('values')]
    public function testDecodingUndoesEncoding(string $column, mixed $value): void
    {
        $stored = $this->schema->encode('field', $column, $value);

        $this->assertSame(
            $value,
            $this->schema->decode('field', $column, $stored),
            'a value written to JCB and read back is the value that went in'
        );
    }

    public function testBase64IsWhatJcbActuallyHolds(): void
    {
        $source = "<?php\nreturn 1;\n";

        $this->assertSame(
            base64_encode($source),
            $this->schema->encode('field', 'php_code', $source)
        );
        $this->assertSame(
            $source,
            $this->schema->decode('field', 'php_code', base64_encode($source))
        );
    }

    /**
     * In a text column an empty string is a value, and stays one.
     *
     * The design projection is right to notice the difference between an empty
     * string and nothing, so nothing here may blur it.
     */
    public function testAnEmptyTextValueIsLeftAlone(): void
    {
        foreach (['name', 'php_code'] as $column) {
            $this->assertSame('', $this->schema->decode('field', $column, ''));
            $this->assertNull($this->schema->decode('field', $column, null));
        }
    }

    /**
     * In a JSON column it is not a value at all.
     *
     * The column's SQL default is `''` and emptying a subform in JCB's
     * interface leaves `[]` behind; a blueprint payload carries `null` for
     * both. Reading them as `''` and `[]` is what this did first, and it was
     * the whole of the disagreement between the same models read off disk and
     * read out of the tables.
     *
     * @param string $stored
     */
    #[DataProvider('emptyJson')]
    public function testAnEmptyJsonColumnHoldsNothing(string $stored): void
    {
        $this->assertNull($this->schema->decode('field', 'settings', $stored));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyJson(): array
    {
        return [
            'the column default' => [''],
            'an emptied subform' => ['[]'],
            'an empty object'    => ['{}'],
            'encoded null'       => ['null'],
            'with whitespace'    => ["  []\n"],
        ];
    }

    public function testAJsonColumnWithSomethingInItIsStillRead(): void
    {
        $this->assertSame(
            ['a' => '1'],
            $this->schema->decode('field', 'settings', '{"a":"1"}')
        );
        $this->assertSame(
            [[]],
            $this->schema->decode('field', 'settings', '[[]]'),
            'a list holding an empty row is not an empty list'
        );
    }

    /**
     * A text column holding `7` is text. `json_decode` would happily make it an
     * integer, and a payload never changes a value's type on the way out.
     */
    public function testATextColumnIsNotQuietlyMadeIntoANumber(): void
    {
        $this->assertSame('7', $this->schema->decode('field', 'name', '7'));
        $this->assertSame('true', $this->schema->decode('field', 'name', 'true'));
        $this->assertSame('null', $this->schema->decode('field', 'name', 'null'));
    }

    /**
     * A column with no declared store may still hold a subform - the subform
     * columns of an owned record are the common case - so it is offered the
     * same reading and keeps its string when it is not one.
     */
    public function testAnUndeclaredColumnHoldingJsonIsStillRead(): void
    {
        $this->assertSame(
            ['tabs0' => ['name' => 'Testing']],
            $this->schema->decode('field', 'name', '{"tabs0":{"name":"Testing"}}')
        );

        $this->assertSame(
            'not json at all {',
            $this->schema->decode('field', 'name', 'not json at all {')
        );
    }

    /**
     * Base64 that is not base64 keeps its string rather than becoming rubbish.
     *
     * Real columns have these: a value written before the column carried an
     * encoding, or one somebody edited by hand in the database.
     */
    public function testSomethingThatIsNotBase64KeepsItsValue(): void
    {
        $this->assertSame(
            'plainly not base64 !!',
            $this->schema->decode('field', 'php_code', 'plainly not base64 !!')
        );
    }

    // -- who owns what -------------------------------------------------------

    /**
     * Ownership is declared, not inferred from the identifier.
     *
     * They look like the same question and are not. `custom_code` is addressed
     * by its function name and `placeholder` by its target; neither has a guid
     * and neither is anybody's child. Reading a missing guid as "this is an
     * owned record" files them under a parent that does not exist - which is
     * exactly what it did, until the same models read off disk and out of the
     * tables disagreed about where one of them lived.
     */
    public function testAnEntityWithoutAGuidIsNotThereforeAChild(): void
    {
        $schema = new Schema([
            'entities' => [
                'admin_view'   => ['transport' => ['guidField' => 'guid', 'children' => ['admin_fields']]],
                'admin_fields' => ['transport' => ['guidField' => 'admin_view', 'children' => []]],
                'custom_code'  => ['transport' => ['guidField' => 'function_name', 'children' => []]],
            ],
        ]);

        $this->assertSame('admin_view', $schema->parentOf('admin_fields'));

        $this->assertNull(
            $schema->parentOf('custom_code'),
            'identified by a natural key, and owned by nobody'
        );
        $this->assertSame(
            'function_name',
            $schema->identifier('custom_code'),
            'which is a separate fact, and the one that says how to address it'
        );

        $this->assertNull($schema->parentOf('admin_view'));
    }
}
