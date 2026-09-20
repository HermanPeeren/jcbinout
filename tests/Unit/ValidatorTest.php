<?php
/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Lionweb\Validator;

/**
 * The validator is what stands between a generated chunk and a consumer that
 * cannot load it, so its rejections matter as much as its acceptance.
 */
final class ValidatorTest extends TestCase
{
	private function node(string $id, ?string $parent = null, array $extra = []): array
	{
		return array_merge([
			'id'           => $id,
			'classifier'   => ['language' => 'LionCore-M3', 'version' => '2024.1', 'key' => 'Concept'],
			'properties'   => [],
			'containments' => [],
			'references'   => [],
			'annotations'  => [],
			'parent'       => $parent,
		], $extra);
	}

	private function chunk(array $nodes): array
	{
		return [
			'serializationFormatVersion' => '2024.1',
			'languages'                  => [['key' => 'LionCore-M3', 'version' => '2024.1']],
			'nodes'                      => $nodes,
		];
	}

	public function testMinimalWellFormedChunkIsValid(): void
	{
		$v = new Validator();

		$this->assertTrue($v->validate($this->chunk([$this->node('root')])), implode('; ', $v->errors()));
		$this->assertNotEmpty($v->checks());
	}

	public function testMissingEnvelopeMemberIsRejected(): void
	{
		$v = new Validator();
		$c = $this->chunk([$this->node('root')]);
		unset($c['languages']);

		$this->assertFalse($v->validate($c));
	}

	public function testDuplicateNodeIdsAreRejected(): void
	{
		$v = new Validator();

		$this->assertFalse($v->validate($this->chunk([
			$this->node('same'),
			$this->node('same', 'same'),
		])));
	}

	/**
	 * LionWeb restricts ids to [a-zA-Z0-9_-]; a JCB GUID passes, a path does not.
	 */
	public function testIdsOutsideTheAllowedCharacterSetAreRejected(): void
	{
		$v = new Validator();

		$this->assertFalse($v->validate($this->chunk([$this->node('has/slash')])));
	}

	public function testChildMustExist(): void
	{
		$v    = new Validator();
		$root = $this->node('root', null, [
			'containments' => [[
				'containment' => ['language' => 'LionCore-M3', 'version' => '2024.1',
					'key' => 'Classifier-features'],
				'children'    => ['nowhere'],
			]],
		]);

		$this->assertFalse($v->validate($this->chunk([$root])));
	}

	public function testParentMustAgreeWithContainment(): void
	{
		$v    = new Validator();
		$root = $this->node('root', null, [
			'containments' => [[
				'containment' => ['language' => 'LionCore-M3', 'version' => '2024.1',
					'key' => 'Classifier-features'],
				'children'    => ['child'],
			]],
		]);

		// The child claims a different parent than the node containing it.
		$child = $this->node('child', 'somewhere-else');

		$this->assertFalse($v->validate($this->chunk([$root, $child])));
	}

	/**
	 * An annotation instance's parent is the annotated node, and it is listed
	 * in "annotations" rather than in a containment. Treating that as a broken
	 * parent link would reject a legitimate chunk.
	 */
	public function testAnnotationParentageIsAccepted(): void
	{
		$v    = new Validator();
		$root = $this->node('root', null, ['annotations' => ['note']]);
		$note = $this->node('note', 'root');

		$this->assertTrue($v->validate($this->chunk([$root, $note])), implode('; ', $v->errors()));
	}

	public function testExactlyOneRootIsRequired(): void
	{
		$v = new Validator();

		$this->assertFalse($v->validate($this->chunk([
			$this->node('a'),
			$this->node('b'),
		])));
	}

	public function testCensusCountsByClassifier(): void
	{
		$census = Validator::census($this->chunk([
			$this->node('a'),
			$this->node('b', 'a'),
		]));

		$this->assertSame(['Concept' => 2], $census);
	}
}
