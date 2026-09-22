<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Blueprint;

\defined('_JEXEC') or die;

/**
 * One payload out of a blueprint: a root definition, or a record owned by one.
 *
 * @since 1.0.0
 */
final class Payload
{
	public function __construct(
		/** JCB entity name, e.g. admin_view or admin_fields. */
		public readonly string $entity,
		/**
		 * For a root definition its own guid; for an owned record the guid of
		 * the definition that owns it, which is the only identity such a record
		 * has - JCB addresses it by its parent relationship, not by a guid of
		 * its own.
		 */
		public readonly string $ownerGuid,
		public readonly string $relativePath,
		public readonly array $data,
		public readonly bool $isChild,
	) {
	}

	/**
	 * A node id for this payload.
	 *
	 * A root definition keeps its guid, which is its portable identity and is
	 * already a legal LionWeb id. An owned record has none, so it takes a
	 * deterministic one derived from its owner - deterministic because a second
	 * export of the same blueprint has to produce the same ids or nothing
	 * downstream can be compared.
	 */
	public function nodeId(): string
	{
		return $this->isChild
			? self::legalId($this->ownerGuid) . '--' . str_replace('_', '-', $this->entity)
			: self::legalId($this->ownerGuid);
	}

	/**
	 * An identity LionWeb will accept, which most of them already are.
	 *
	 * A LionWeb id is `[a-zA-Z0-9_-]+`, and a GUID is one already - so every
	 * definition keeps the identity it has and nothing that used to export
	 * changes. Not every JCB identity is a GUID, though: `placeholder` is
	 * addressed by its target, and a target reads `[[[COMPANY]]]`, which is
	 * not a legal id and stops a whole chunk validating.
	 *
	 * So an identity that cannot be an id is *encoded* into one rather than
	 * replaced by one. The readable part is kept for whoever reads the file,
	 * and a hash of the original is appended so two targets that differ only
	 * in punctuation stay two nodes.
	 */
	private static function legalId(string $identity): string
	{
		if ($identity !== '' && preg_match('#^[a-zA-Z0-9_-]+$#', $identity) === 1)
		{
			return $identity;
		}

		$readable = trim((string) preg_replace('#[^a-zA-Z0-9_-]+#', '-', $identity), '-');

		return ($readable === '' ? 'x' : $readable) . '-' . substr(sha1($identity), 0, 12);
	}

	/** Payload keys that carry design, excluding transport bookkeeping. */
	public function designKeys(): array
	{
		return array_diff(array_keys($this->data), [RepositorySource::DEPENDENCIES]);
	}

	/** @return list<array> the reserved @dependencies descriptors */
	public function dependencies(): array
	{
		$deps = $this->data[RepositorySource::DEPENDENCIES] ?? [];

		return is_array($deps) ? $deps : [];
	}
}
