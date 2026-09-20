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

	public function testRadioOnANarrowIntIsBoolean(): void
	{
		$r = $this->classifier->classify([
			'type' => 'radio',
			'db'   => ['type' => 'TINYINT(1)'],
		]);

		$this->assertSame('Boolean', $r['datatype']);
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
