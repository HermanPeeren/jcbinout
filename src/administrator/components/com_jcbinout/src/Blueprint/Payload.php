<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
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
			? $this->ownerGuid . '--' . str_replace('_', '-', $this->entity)
			: $this->ownerGuid;
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
