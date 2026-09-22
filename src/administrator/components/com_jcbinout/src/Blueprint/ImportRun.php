<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Blueprint;

\defined('_JEXEC') or die;

/**
 * One import, in progress.
 *
 * A plan of any size is written in one request until the request runs out of
 * time, and then the next one carries on where it stopped. That is the whole
 * reason this exists: an import is not transactional, so a plan that dies
 * half-written leaves rows behind either way - the difference is whether
 * anything knows which ones.
 *
 * It holds a cursor and what has happened so far, and nothing else. No
 * database, no session, no clock: it is handed a slice's outcome and folds it
 * in. That keeps the arithmetic of "how far along is this" testable, and it
 * keeps {@see \Yepr\Component\Jcbinout\Administrator\Jcb\LocalStore} the only
 * thing that touches JCB's tables.
 *
 * @since 1.0.0
 */
final class ImportRun
{
	/**
	 * Where a run in progress is kept between requests.
	 */
	public const STATE_KEY = 'com_jcbinout.import.run';

	/**
	 * How many failures are kept in full.
	 *
	 * The rest are counted. A run's state travels in the session, and a
	 * blueprint that fails on every row would otherwise put a copy of its own
	 * failure list in there - the first fifty say what went wrong just as well.
	 */
	private const FAILURES_KEPT = 50;

	private array $plan;
	private int $position;
	private int $applied;
	private int $failed;
	private int $skipped;
	private array $failures;
	private int $failuresDropped;
	private int $slices;
	private float $startedAt;

	/**
	 * The most operations one slice may write, or 0 for as many as there is
	 * time for.
	 *
	 * A ceiling on top of the deadline, not instead of it. A busy shared host
	 * is not only short of execution time - it is short of everything else at
	 * the same time - and being able to say "fifty rows a request, and I will
	 * wait" is the difference between an import that finishes and one that is
	 * abandoned.
	 */
	private int $limit;

	public function __construct(
		array $plan,
		int $position = 0,
		int $applied = 0,
		int $failed = 0,
		int $skipped = 0,
		array $failures = [],
		int $failuresDropped = 0,
		int $slices = 0,
		?float $startedAt = null,
		int $limit = 0
	) {
		$this->limit = max(0, $limit);
		$this->plan            = $plan + ['mode' => '', 'operations' => [], 'counts' => []];
		$this->position        = max(0, $position);
		$this->applied         = $applied;
		$this->failed          = $failed;
		$this->skipped         = $skipped;
		$this->failures        = $failures;
		$this->failuresDropped = $failuresDropped;
		$this->slices          = $slices;
		$this->startedAt       = $startedAt ?? microtime(true);
	}

	public function mode(): string
	{
		return (string) $this->plan['mode'];
	}

	public function total(): int
	{
		return \count($this->plan['operations']);
	}

	public function position(): int
	{
		return $this->position;
	}

	public function remaining(): int
	{
		return max(0, $this->total() - $this->position);
	}

	public function isFinished(): bool
	{
		return $this->remaining() === 0;
	}

	public function slices(): int
	{
		return $this->slices;
	}

	public function elapsed(): float
	{
		return microtime(true) - $this->startedAt;
	}

	/**
	 * How far along, as a whole number for a progress bar.
	 *
	 * An empty plan is finished, not nowhere: a run with nothing to do should
	 * read 100, or the bar sits at zero and looks stuck.
	 */
	public function percentage(): int
	{
		if ($this->total() === 0)
		{
			return 100;
		}

		return (int) floor($this->position / $this->total() * 100);
	}

	/**
	 * What is left to write, shaped like a plan so the writer needs no notion
	 * of a run at all.
	 */
	public function slice(): array
	{
		return [
			'mode'       => $this->mode(),
			'operations' => \array_slice(
				$this->plan['operations'], $this->position, $this->limit ?: null
			),
			'counts'     => $this->plan['counts'],
		];
	}

	public function limit(): int
	{
		return $this->limit;
	}

	/**
	 * Fold a slice's outcome in and advance the cursor.
	 *
	 * The cursor moves by what the writer says it consumed, not by what was
	 * applied: an operation that was skipped or that failed is still an
	 * operation that has been dealt with, and re-offering it would mean a
	 * failing row blocked the run for ever.
	 *
	 * @param array{applied?:int,failed?:int,skipped?:int,consumed?:int,results?:array} $result
	 */
	public function record(array $result): void
	{
		$consumed = (int) ($result['consumed']
			?? (($result['applied'] ?? 0) + ($result['failed'] ?? 0) + ($result['skipped'] ?? 0)));

		$this->position = min($this->total(), $this->position + max(0, $consumed));
		$this->applied += (int) ($result['applied'] ?? 0);
		$this->failed  += (int) ($result['failed'] ?? 0);
		$this->skipped += (int) ($result['skipped'] ?? 0);
		$this->slices++;

		foreach ($result['results'] ?? [] as $failure)
		{
			if (\count($this->failures) < self::FAILURES_KEPT)
			{
				$this->failures[] = $failure;

				continue;
			}

			$this->failuresDropped++;
		}
	}

	/**
	 * Move the cursor without recording an outcome.
	 *
	 * For the checkpoint a slice writes while it runs: the operations really
	 * have been dealt with, and if the request dies before it can report on
	 * them the cursor is still the honest place to resume from. What they did
	 * is lost, which is why the counts are left alone and the totals are
	 * reconciled when a slice returns properly.
	 */
	public function checkpoint(int $consumed): void
	{
		$this->position = min($this->total(), max($this->position, $consumed));
	}

	/**
	 * @return array{applied:int,failed:int,skipped:int}
	 */
	public function counts(): array
	{
		return [
			'applied' => $this->applied,
			'failed'  => $this->failed,
			'skipped' => $this->skipped,
		];
	}

	public function failures(): array
	{
		return $this->failures;
	}

	public function failuresDropped(): int
	{
		return $this->failuresDropped;
	}

	public function toArray(): array
	{
		return [
			'plan'            => $this->plan,
			'position'        => $this->position,
			'applied'         => $this->applied,
			'failed'          => $this->failed,
			'skipped'         => $this->skipped,
			'failures'        => $this->failures,
			'failuresDropped' => $this->failuresDropped,
			'slices'          => $this->slices,
			'startedAt'       => $this->startedAt,
			'limit'           => $this->limit,
		];
	}

	/**
	 * Read a run back out of wherever it was kept.
	 *
	 * Anything missing or the wrong shape reads as its empty value rather than
	 * throwing. Session state is not a file format and a half-written one is a
	 * reason to start over, not to break the page it is shown on.
	 */
	public static function fromArray(mixed $state): ?self
	{
		if (!\is_array($state) || !\is_array($state['plan'] ?? null))
		{
			return null;
		}

		return new self(
			$state['plan'],
			(int) ($state['position'] ?? 0),
			(int) ($state['applied'] ?? 0),
			(int) ($state['failed'] ?? 0),
			(int) ($state['skipped'] ?? 0),
			\is_array($state['failures'] ?? null) ? $state['failures'] : [],
			(int) ($state['failuresDropped'] ?? 0),
			(int) ($state['slices'] ?? 0),
			isset($state['startedAt']) ? (float) $state['startedAt'] : null,
			(int) ($state['limit'] ?? 0)
		);
	}
}
