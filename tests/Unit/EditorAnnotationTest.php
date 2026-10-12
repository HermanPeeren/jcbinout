<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageBuilder;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageIndex;
use Yepr\Component\Jcbinout\Administrator\Lionweb\Validator;

/**
 * A String property that JCB edits over several lines says so in the language,
 * as a `yepr-forms` editor annotation on its Property node. Meta-gen reads it
 * and generates a textarea, so a browser save in Exten-gen keeps line breaks.
 *
 * The node shape is Meta-gen's contract (`Lionweb\EditorAnnotation::node()`),
 * so it is asserted against a copy of that shape rather than against this
 * builder's own idea of it.
 */
final class EditorAnnotationTest extends TestCase
{
    /**
     * @return array<string, array{array, ?string}>
     */
    public static function widgets(): array
    {
        return [
            'code editor'               => [['jcbType' => 'editor', 'db' => ['type' => 'MEDIUMTEXT']], 'code'],
            'code editor on TEXT'       => [['jcbType' => 'editor', 'db' => ['type' => 'TEXT']], 'code'],
            'hidden over TEXT'          => [['jcbType' => 'hidden', 'db' => ['type' => 'TEXT']], 'code'],
            'hidden over MEDIUMTEXT'    => [['jcbType' => 'hidden', 'db' => ['type' => 'MEDIUMTEXT']], 'code'],
            'hidden, lower-case column' => [['jcbType' => 'hidden', 'db' => ['type' => 'text']], 'code'],
            'hidden over VARCHAR'       => [['jcbType' => 'hidden', 'db' => ['type' => 'VARCHAR(255)']], null],
            'hidden over CHAR(64)'      => [['jcbType' => 'hidden', 'db' => ['type' => 'CHAR(64)']], null],
            'hidden with no column'     => [['jcbType' => 'hidden'], null],
            'textarea'                  => [['jcbType' => 'textarea', 'db' => ['type' => 'TEXT']], 'text'],
            'textarea on VARCHAR'       => [['jcbType' => 'textarea', 'db' => ['type' => 'VARCHAR(255)']], 'text'],
            'text box on TEXT'          => [['jcbType' => 'text', 'db' => ['type' => 'TEXT']], null],
            'list'                      => [['jcbType' => 'list', 'db' => ['type' => 'VARCHAR(255)']], null],
            'no widget'                 => [[], null],
        ];
    }

    #[DataProvider('widgets')]
    public function testTheWidgetDecidesTheEditor(array $property, ?string $expected): void
    {
        $this->assertSame($expected, LanguageBuilder::editorFor($property));
    }

    /**
     * Copied from Meta-gen's `EditorAnnotation::node($id, $propertyNodeId, $editor)`
     * (branch feature/multiline-strings, 5e8be7b), key order included.
     */
    private static function metagenNode(string $id, string $propertyNodeId, string $editor): array
    {
        $pointer = static fn (string $key): array => [
            'language' => 'yepr-forms',
            'version'  => '1',
            'key'      => $key,
        ];

        return [
            'id'           => $id,
            'classifier'   => $pointer('yepr-forms-Editor'),
            'properties'   => [['property' => $pointer('yepr-forms-Editor-value'), 'value' => $editor]],
            'containments' => [],
            'references'   => [],
            'annotations'  => [],
            'parent'       => $propertyNodeId,
        ];
    }

    private static function property(string $guid, string $widget, string $column, string $datatype = 'String'): array
    {
        return [
            'guid'     => $guid,
            'jcbType'  => $widget,
            'kind'     => 'property',
            'portable' => true,
            'db'       => ['type' => $column],
            'datatype' => $datatype,
        ];
    }

    /** A metamodel small enough to read: one entity, one subform row. */
    private static function metamodel(): array
    {
        return [
            'entities' => [
                'snippet' => [
                    'portable'   => true,
                    'properties' => [
                        'name'    => self::property('g-name', 'text', 'VARCHAR(255)'),
                        'php'     => self::property('g-php', 'editor', 'MEDIUMTEXT'),
                        'notes'   => self::property('g-notes', 'textarea', 'TEXT'),
                        'xml'     => ['kind' => 'structured', 'datatype' => 'JsonBlob']
                            + self::property('g-xml', 'hidden', 'TEXT'),
                        'guid'    => self::property('g-guid', 'hidden', 'VARCHAR(36)'),
                        'counter' => self::property('g-counter', 'textarea', 'INT(11)', 'Integer'),
                        'lines'   => [
                            'guid'     => 'g-lines',
                            'jcbType'  => 'subform',
                            'kind'     => 'containment',
                            'portable' => true,
                            'subform'  => [
                                'rowConcept' => 'snippet_lines',
                                'fields'     => [
                                    'title'   => ['jcbType' => 'text', 'kind' => 'property'],
                                    'comment' => ['jcbType' => 'textarea', 'kind' => 'property'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function byId(array $chunk): array
    {
        $out = [];

        foreach ($chunk['nodes'] as $node) {
            $out[$node['id']] = $node;
        }

        return $out;
    }

    public function testTheAnnotationNodeIsTheShapeMetagenReads(): void
    {
        $builder = new LanguageBuilder(self::metamodel());
        $chunk   = $builder->build('1.0.0');
        $nodes   = self::byId($chunk);

        $this->assertSame(
            self::metagenNode('jcb-snippet-php-editor', 'jcb-snippet-php', 'code'),
            $nodes['jcb-snippet-php-editor']
        );
        $this->assertSame(
            self::metagenNode('jcb-snippet-notes-editor', 'jcb-snippet-notes', 'text'),
            $nodes['jcb-snippet-notes-editor']
        );
        $this->assertSame(
            self::metagenNode('jcb-snippet-xml-editor', 'jcb-snippet-xml', 'code'),
            $nodes['jcb-snippet-xml-editor']
        );
        $this->assertSame(
            self::metagenNode('jcb-snippet_lines-comment-editor', 'jcb-snippet_lines-comment', 'text'),
            $nodes['jcb-snippet_lines-comment-editor']
        );

        // The Property node lists its annotation; LionWeb holds both ends.
        $this->assertSame(['jcb-snippet-php-editor'], $nodes['jcb-snippet-php']['annotations']);
        $this->assertSame('Property', $nodes['jcb-snippet-php']['classifier']['key']);

        $this->assertContains(['key' => 'yepr-forms', 'version' => '1'], $chunk['languages']);

        $v = new Validator();
        $this->assertTrue($v->validate($chunk), implode('; ', $v->errors()));
    }

    public function testOneLinePropertiesAndNonStringsCarryNoAnnotation(): void
    {
        $builder = new LanguageBuilder(self::metamodel());
        $nodes   = self::byId($builder->build('1.0.0'));

        foreach (['jcb-snippet-name', 'jcb-snippet-guid', 'jcb-snippet-counter', 'jcb-snippet_lines-title'] as $id) {
            $this->assertSame([], $nodes[$id]['annotations'], $id);
            $this->assertArrayNotHasKey($id . '-editor', $nodes);
        }

        $this->assertSame(
            ['snippet-g-notes' => 'text', 'snippet-g-php' => 'code', 'snippet-g-xml' => 'code',
                'snippet_lines-comment' => 'text'],
            $builder->editors()
        );
    }

    public function testALanguageWithoutMultiLinePropertiesDoesNotNameTheAnnotationLanguage(): void
    {
        $meta = self::metamodel();

        foreach (['php', 'notes', 'xml', 'lines'] as $multiLine) {
            unset($meta['entities']['snippet']['properties'][$multiLine]);
        }

        $chunk = (new LanguageBuilder($meta))->build('1.0.0');

        $this->assertSame(['LionCore-M3', 'LionCore-builtins'], array_column($chunk['languages'], 'key'));

        foreach ($chunk['nodes'] as $node) {
            $this->assertSame([], $node['annotations'], $node['id']);
        }
    }

    /**
     * A reader that has never heard of `yepr-forms` sees the language it saw
     * before: the annotation nodes are not features of anything.
     */
    public function testAnAnnotationUnawareReaderSeesTheSameLanguage(): void
    {
        $chunk    = (new LanguageBuilder(self::metamodel()))->build('1.0.0');
        $stripped = $chunk;

        $stripped['nodes'] = array_values(array_filter(
            $chunk['nodes'],
            static fn (array $n): bool => $n['classifier']['key'] !== 'yepr-forms-Editor'
        ));

        foreach ($stripped['nodes'] as $i => $node) {
            $stripped['nodes'][$i]['annotations'] = [];
        }

        $with    = new LanguageIndex($chunk);
        $without = new LanguageIndex($stripped);

        foreach (['snippet', 'snippet_lines'] as $concept) {
            $this->assertSame($without->featuresOf($concept), $with->featuresOf($concept), $concept);
        }
    }

    /**
     * JCB's own language, derived from the metamodel in data/: how many of its
     * properties become code and text. A field definition shared by several
     * entities is declared once, on an interface, so it is annotated once.
     */
    public function testTheDerivedJcbLanguageMarksItsMultiLineProperties(): void
    {
        $root = \dirname(__DIR__, 2);
        $meta = $root . '/data/jcb-metamodel.json';

        if (!is_file($meta)) {
            $this->markTestSkipped('No metamodel derived yet. Run: php tools/cli.php derive');
        }

        $names   = json_decode((string) file_get_contents($root . '/data/interface-names.json'), true);
        $builder = new LanguageBuilder(
            json_decode((string) file_get_contents($meta), true),
            $names['interfaces'] ?? []
        );
        $chunk = $builder->build('6.2.0');

        $this->assertSame(['code' => 78, 'text' => 40], array_count_values($builder->editors()));

        $perOwner = [];
        $nodes    = self::byId($chunk);

        foreach ($chunk['nodes'] as $node) {
            if ($node['classifier']['key'] !== 'yepr-forms-Editor') {
                continue;
            }

            $owner = $nodes[$nodes[$node['parent']]['parent']]['classifier']['key'];
            $value = $node['properties'][0]['value'];

            $perOwner[$value . ' on ' . $owner] = ($perOwner[$value . ' on ' . $owner] ?? 0) + 1;
        }

        ksort($perOwner);

        $this->assertSame(
            ['code on Concept' => 50, 'code on Interface' => 28, 'text on Concept' => 36, 'text on Interface' => 4],
            $perOwner
        );

        // The committed language is the one this builds.
        $committed = json_decode((string) file_get_contents($root . '/data/jcb-language.lionweb.json'), true);
        $this->assertSame(118, Validator::census($committed)['yepr-forms-Editor'] ?? 0);
        $this->assertContains(['key' => 'yepr-forms', 'version' => '1'], $committed['languages']);
    }
}
