<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;

/**
 * The node id a payload becomes.
 *
 * A LionWeb id is `[a-zA-Z0-9_-]+` and a GUID already is one, so for almost
 * everything this is the identity JCB already has and nothing has to be
 * invented. Almost: not every JCB identity is a GUID, and one that is not can
 * be a string no chunk will accept.
 *
 * Found by exporting a whole installation rather than one blueprint. The Hello
 * World fixture has no placeholders in it, so nothing had ever asked what
 * `[[[COMPANY]]]` becomes.
 */
final class PayloadIdentityTest extends TestCase
{
    private const LEGAL = '#^[a-zA-Z0-9_-]+$#';

    private function payload(string $entity, string $owner, bool $child = false): Payload
    {
        return new Payload($entity, $owner, 'src/x.json', [], $child);
    }

    /**
     * A GUID is already a legal id, and keeps it.
     *
     * This is the one that must not change: a definition's portable identity is
     * its node id, so anything that rewrote a GUID would break every export
     * that has already been compared against another.
     */
    public function testAGuidIsItsOwnNodeId(): void
    {
        $guid = '75e830a6-a6d2-4b8e-9a6b-1c6c9dcf4d94';

        $this->assertSame($guid, $this->payload('field', $guid)->nodeId());
    }

    public function testAnOwnedRecordDerivesItsIdFromItsOwner(): void
    {
        $guid = '65116558-be67-4931-95be-727fbfb16db7';

        $this->assertSame(
            $guid . '--admin-fields',
            $this->payload('admin_fields', $guid, true)->nodeId()
        );
    }

    /**
     * An identity a chunk will not accept is encoded, not replaced.
     *
     * `placeholder` is addressed by its target, and a target reads
     * `[[[COMPANY]]]`. One of those in an installation stops the whole chunk
     * validating.
     */
    public function testAnIdentityThatIsNotALegalIdBecomesOne(): void
    {
        $id = $this->payload('placeholder', '[[[COMPANY]]]')->nodeId();

        $this->assertMatchesRegularExpression(self::LEGAL, $id);
        $this->assertStringStartsWith('COMPANY-', $id, 'still readable to whoever opens the file');
    }

    /**
     * Two identities that differ only in punctuation stay two nodes.
     *
     * Sanitising alone would map both to `COMPANY`, and one would silently
     * overwrite the other in a chunk that still validated.
     */
    public function testTwoIdentitiesThatSanitiseAlikeStayApart(): void
    {
        $first  = $this->payload('placeholder', '[[[COMPANY]]]')->nodeId();
        $second = $this->payload('placeholder', '[[COMPANY]]')->nodeId();

        $this->assertNotSame($first, $second);
    }

    /**
     * And the same identity is the same id every time, or two exports of one
     * installation cannot be compared.
     */
    public function testTheSameIdentityGivesTheSameIdEveryTime(): void
    {
        $this->assertSame(
            $this->payload('placeholder', '[[[COMPANY]]]')->nodeId(),
            $this->payload('placeholder', '[[[COMPANY]]]')->nodeId()
        );
    }

    /**
     * An identity with nothing legal in it at all still gets an id.
     */
    public function testAnIdentityWithNothingUsableInItStillGetsAnId(): void
    {
        $id = $this->payload('placeholder', '[[[...]]]')->nodeId();

        $this->assertMatchesRegularExpression(self::LEGAL, $id);
    }

    /**
     * An owned record whose owner is not a legal id is legal too: the owner is
     * encoded before the entity is appended, not after.
     */
    public function testAnOwnedRecordOfAnAwkwardOwnerIsStillLegal(): void
    {
        $id = $this->payload('library_config', '[[[LIB]]]', true)->nodeId();

        $this->assertMatchesRegularExpression(self::LEGAL, $id);
        $this->assertStringEndsWith('--library-config', $id);
    }
}
