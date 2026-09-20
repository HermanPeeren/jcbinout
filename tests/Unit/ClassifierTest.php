<?php
/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Metamodel\Classifier;

/**
 * The classifier decides what each JCB column becomes in LionWeb terms. Those
 * decisions are what the whole language is built from, so they are pinned here
 * rather than left to be re-derived by reading the code.
 */
final class ClassifierTest extends TestCase
{
	private Classifier $classifier;

	protected function setUp(): void
	{
		$this->classifier = new Classifier();
	}

	public function testPlainTextColumnBecomesAStringProperty(): void
	{
		$r = $this->classifier->classify([
			'type'  => 'text',
			'store' => null,
			'link'  => null,
			'db'    => ['type' => 'VARCHAR(255)'],
		]);

		$this->assertSame('property', $r['kind']);
		$this->assertSame('String', $r['datatype']);
		$this->assertTrue($r['portable']);
	}

	public function testGuidLinkBecomesAPortableReference(): void
	{
		$r = $this->classifier->classify([
			'type' => 'list',
			'link' => ['entity' => 'admin_view', 'key' => 'guid'],
		]);

		$this->assertSame('reference', $r['kind']);
		$this->assertSame('admin_view', $r['target']);
		$this->assertTrue($r['portable']);
	}

	/**
	 * A link keyed on a local row id cannot survive transport between
	 * installations, so it must not be emitted as a portable reference.
	 */
	public function testLocalIdLinkIsNotPortable(): void
	{
		$r = $this->classifier->classify([
			'type' => 'list',
			'link' => ['entity' => 'server', 'key' => 'id'],
		]);

		$this->assertSame('reference_local', $r['kind']);
		$this->assertFalse($r['portable']);
	}

	public function testEncryptedColumnIsNeverPortable(): void
	{
		$r = $this->classifier->classify([
			'type'  => 'password',
			'store' => 'basic_encryption',
		]);

		$this->assertSame('secret', $r['kind']);
		$this->assertFalse($r['portable']);
	}

	public function testSubformWithRowShapeBecomesAContainment(): void
	{
		$r = $this->classifier->classify([
			'type'   => 'subform',
			'store'  => 'json',
			'fields' => ['field' => ['name' => 'field', 'type' => 'ModalSelect']],
		]);

		$this->assertSame('containment', $r['kind']);
		$this->assertTrue($r['hasRowShape']);
	}

	public function testSubformWithoutRowShapeIsFlaggedLossy(): void
	{
		$r = $this->classifier->classify(['type' => 'subform', 'store' => 'json']);

		$this->assertSame('containment', $r['kind']);
		$this->assertFalse($r['hasRowShape']);
	}

	/**
	 * A radio on TINYINT(1) is not a yes/no.
	 *
	 * MySQL's (1) is a display width, not a range: the column holds -128..127,
	 * and JCB really does store 2 and 3 in these - component_router.mode_methods
	 * and joomla_component.update_server_target both do. Reading them as
	 * Boolean turned a 3 into false and the round trip brought back 0.
	 */
	public function testRadioOnANarrowIntIsAnInteger(): void
	{
		$r = $this->classifier->classify([
			'type' => 'radio',
			'db'   => ['type' => 'TINYINT(1)'],
		]);

		$this->assertSame('Integer', $r['datatype']);
	}

	/**
	 * Nor is every radio numeric: the column decides, not the widget.
	 * joomla_component.add_namespace_prefix is a radio on CHAR(1) whose
	 * default is '', which is not an integer at all.
	 */
	public function testRadioOnACharColumnIsAString(): void
	{
		$r = $this->classifier->classify([
			'type' => 'radio',
			'db'   => ['type' => 'CHAR(1)', 'default' => ''],
		]);

		$this->assertSame('String', $r['datatype']);
	}

	public function testAnIntegerColumnIsAnInteger(): void
	{
		$r = $this->classifier->classify([
			'type' => 'number',
			'db'   => ['type' => 'INT(11)'],
		]);

		$this->assertSame('Integer', $r['datatype']);
	}

	public function testBase64StoredColumnRecordsItsEncoding(): void
	{
		$r = $this->classifier->classify([
			'type'  => 'editor',
			'store' => 'base64',
			'db'    => ['type' => 'MEDIUMTEXT'],
		]);

		$this->assertSame('property', $r['kind']);
		$this->assertSame('base64', $r['encoding']);
	}

	/**
	 * A subform row that references a definition is an occurrence: the
	 * reference identifies the definition, the rest are use-site settings.
	 */
	public function testRowReferenceIsDistinguishedFromRowSettings(): void
	{
		$rows = $this->classifier->classifyRow([
			'field' => ['name' => 'field', 'type' => 'ModalSelect',
				'link' => ['entity' => 'field', 'key' => 'guid']],
			'title' => ['name' => 'title', 'type' => 'radio'],
		]);

		$this->assertSame('reference', $rows['field']['kind']);
		$this->assertSame('field', $rows['field']['target']);
		$this->assertSame('property', $rows['title']['kind']);
	}
}
