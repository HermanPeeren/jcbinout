<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$status    = $this->status;
$jcb       = $status['jcb'];
$metamodel = $status['metamodel'];
$language  = $status['language'];
$instance  = $status['instance'] ?? null;
$plan      = $this->importPlan;
$run       = $this->importRun;
$running   = $run !== null && !$run->isFinished();

$badge = static function (bool $ok, string $yes, string $no): string {
	return '<span class="badge bg-' . ($ok ? 'success' : 'danger') . '">'
		. htmlspecialchars($ok ? $yes : $no, ENT_QUOTES, 'UTF-8') . '</span>';
};

$bytes = static function (int $n): string {
	return $n > 1048576 ? round($n / 1048576, 1) . ' MB'
		: ($n > 1024 ? round($n / 1024) . ' KB' : $n . ' B');
};
?>
<form action="<?php echo Route::_('index.php?option=com_jcbinout&view=metamodel'); ?>"
	method="post" name="adminForm" id="adminForm">

	<div class="row">
		<div class="col-md-6">
			<div class="card mb-3">
				<div class="card-header">
					<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_JCB_STATUS'); ?></h2>
				</div>
				<div class="card-body">
					<dl class="row mb-0">
						<dt class="col-sm-5"><?php echo Text::_('COM_JCBINOUT_JCB_INSTALLED'); ?></dt>
						<dd class="col-sm-7"><?php echo $badge((bool) $jcb['componentInstalled'],
							Text::_('JYES'), Text::_('JNO')); ?></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_JCBINOUT_JCB_VERSION'); ?></dt>
						<dd class="col-sm-7"><?php echo $jcb['version']
							? htmlspecialchars($jcb['version'], ENT_QUOTES, 'UTF-8')
							: '<em>' . Text::_('COM_JCBINOUT_UNKNOWN') . '</em>'; ?></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_JCBINOUT_JCB_CLASSES'); ?></dt>
						<dd class="col-sm-7"><?php echo $badge((bool) $jcb['classesAvailable'],
							Text::_('COM_JCBINOUT_READABLE'), Text::_('COM_JCBINOUT_NOT_LOADED')); ?></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_JCBINOUT_JCB_SOURCE'); ?></dt>
						<dd class="col-sm-7"><code class="small"><?php echo $jcb['sourcePath']
							? htmlspecialchars($jcb['sourcePath'], ENT_QUOTES, 'UTF-8')
							: Text::_('COM_JCBINOUT_NOT_FOUND'); ?></code></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_JCBINOUT_SCHEMA_FINGERPRINT'); ?></dt>
						<dd class="col-sm-7"><code class="small"><?php echo $jcb['schemaFingerprint']
							? substr($jcb['schemaFingerprint'], 0, 16) . '&hellip;'
							: '&mdash;'; ?></code></dd>
					</dl>

					<?php if (!$jcb['componentInstalled']) : ?>
						<div class="alert alert-warning mt-3 mb-0">
							<?php echo Text::_('COM_JCBINOUT_JCB_REQUIRED'); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-md-6">
			<div class="card mb-3">
				<div class="card-header">
					<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_DERIVED_METAMODEL'); ?></h2>
				</div>
				<div class="card-body">
					<?php if (!$metamodel['info']['exists']) : ?>
						<p class="mb-0"><?php echo Text::_('COM_JCBINOUT_NOT_DERIVED_YET'); ?></p>
					<?php else : ?>
						<?php $s = $metamodel['stats']; ?>
						<dl class="row mb-0">
							<dt class="col-sm-6"><?php echo Text::_('COM_JCBINOUT_ENTITY_TYPES'); ?></dt>
							<dd class="col-sm-6"><?php echo (int) $s['entitiesTotal']; ?>
								(<?php echo (int) $s['entitiesPortable']; ?>
								<?php echo Text::_('COM_JCBINOUT_PORTABLE'); ?>)</dd>

							<dt class="col-sm-6"><?php echo Text::_('COM_JCBINOUT_PROPERTIES'); ?></dt>
							<dd class="col-sm-6"><?php echo (int) $s['propertiesTotal']; ?></dd>

							<dt class="col-sm-6"><?php echo Text::_('COM_JCBINOUT_ENUMERATIONS'); ?></dt>
							<dd class="col-sm-6"><?php echo (int) $s['enumerations']; ?>
								(<?php echo (int) $s['enumerationLiterals']; ?>
								<?php echo Text::_('COM_JCBINOUT_LITERALS'); ?>)</dd>

							<dt class="col-sm-6"><?php echo Text::_('COM_JCBINOUT_DERIVED_AT'); ?></dt>
							<dd class="col-sm-6"><?php echo htmlspecialchars(
								(string) $metamodel['info']['modified'], ENT_QUOTES, 'UTF-8'); ?></dd>
						</dl>

						<?php if ($status['stale'] === true) : ?>
							<div class="alert alert-warning mt-3 mb-0">
								<?php echo Text::_('COM_JCBINOUT_STALE'); ?>
							</div>
						<?php endif; ?>

						<?php if (!empty($s['byKind'])) : ?>
							<h3 class="h6 mt-3"><?php echo Text::_('COM_JCBINOUT_BY_KIND'); ?></h3>
							<ul class="list-inline mb-0">
								<?php foreach ($s['byKind'] as $kind => $n) : ?>
									<li class="list-inline-item">
										<span class="badge bg-secondary">
											<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>
											<?php echo (int) $n; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_LIONWEB_LANGUAGE'); ?></h2>
		</div>
		<div class="card-body">
			<?php if (!$language['info']['exists']) : ?>
				<p class="mb-0"><?php echo Text::_('COM_JCBINOUT_NOT_BUILT_YET'); ?></p>
			<?php else : ?>
				<p>
					<?php echo Text::sprintf('COM_JCBINOUT_LANGUAGE_SIZE',
						$bytes((int) $language['info']['size'])); ?>
					&mdash;
					<?php echo htmlspecialchars((string) $language['info']['modified'], ENT_QUOTES, 'UTF-8'); ?>
				</p>
				<table class="table table-sm w-auto mb-0">
					<thead>
						<tr>
							<th><?php echo Text::_('COM_JCBINOUT_NODE_KIND'); ?></th>
							<th class="text-end"><?php echo Text::_('COM_JCBINOUT_COUNT'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($language['census'] ?? [] as $kind => $n) : ?>
							<tr>
								<td><?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?></td>
								<td class="text-end"><?php echo (int) $n; ?></td>
							</tr>
						<?php endforeach; ?>
						<tr class="fw-bold">
							<td><?php echo Text::_('COM_JCBINOUT_TOTAL'); ?></td>
							<td class="text-end"><?php echo array_sum($language['census'] ?? []); ?></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_EXPORT_BLUEPRINT'); ?></h2>
		</div>
		<div class="card-body">
			<p><?php echo Text::_('COM_JCBINOUT_EXPORT_INTRO'); ?></p>

			<div class="mb-3">
				<label class="form-label" for="blueprint">
					<?php echo Text::_('COM_JCBINOUT_BLUEPRINT_PATH'); ?>
				</label>
				<input type="text" class="form-control" id="blueprint" name="blueprint"
					value="<?php echo htmlspecialchars($this->defaultBlueprint, ENT_QUOTES, 'UTF-8'); ?>">
				<small class="form-text"><?php echo Text::_('COM_JCBINOUT_BLUEPRINT_PATH_HELP'); ?></small>
			</div>

			<div class="mb-3">
				<label class="form-label" for="repository"><?php echo Text::_('COM_JCBINOUT_REPOSITORY_PATH'); ?></label>
				<input type="text" class="form-control" id="repository" name="repository"
					value="<?php echo htmlspecialchars($this->defaultRepository, ENT_QUOTES, 'UTF-8'); ?>">
				<small class="form-text"><?php echo Text::_('COM_JCBINOUT_REPOSITORY_PATH_HELP'); ?></small>
			</div>

			<?php if ($instance !== null && $instance['exists']) : ?>
				<p class="mb-0">
					<span class="badge bg-success"><?php echo Text::_('COM_JCBINOUT_EXPORTED'); ?></span>
					<?php echo $bytes((int) $instance['size']); ?>
					&mdash;
					<?php echo htmlspecialchars((string) $instance['modified'], ENT_QUOTES, 'UTF-8'); ?>
				</p>
			<?php else : ?>
				<p class="mb-0"><?php echo Text::_('COM_JCBINOUT_NOT_EXPORTED_YET'); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($running) : ?>
		<?php $counts = $run->counts(); ?>
		<div class="card mb-3 border-warning">
			<div class="card-header">
				<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_RUN_IN_PROGRESS'); ?></h2>
			</div>
			<div class="card-body">
				<p><?php echo Text::sprintf('COM_JCBINOUT_RUN_INTRO',
					htmlspecialchars($run->mode(), ENT_QUOTES, 'UTF-8')); ?></p>

				<div class="progress mb-3" role="progressbar"
					aria-valuenow="<?php echo $run->percentage(); ?>"
					aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar progress-bar-striped progress-bar-animated"
						style="width: <?php echo $run->percentage(); ?>%">
						<?php echo $run->percentage(); ?>%
					</div>
				</div>

				<dl class="row mb-0">
					<dt class="col-sm-3"><?php echo Text::_('COM_JCBINOUT_RUN_POSITION'); ?></dt>
					<dd class="col-sm-9"><?php echo Text::sprintf('COM_JCBINOUT_RUN_OF',
						$run->position(), $run->total()); ?></dd>

					<dt class="col-sm-3"><?php echo Text::_('COM_JCBINOUT_RUN_WRITTEN'); ?></dt>
					<dd class="col-sm-9"><?php echo Text::sprintf('COM_JCBINOUT_RUN_COUNTS',
						$counts['applied'], $counts['skipped'], $counts['failed']); ?></dd>

					<dt class="col-sm-3"><?php echo Text::_('COM_JCBINOUT_RUN_SLICES'); ?></dt>
					<dd class="col-sm-9"><?php echo Text::sprintf('COM_JCBINOUT_RUN_SLICES_VALUE',
						$run->slices(), round($run->elapsed())); ?></dd>
				</dl>

				<?php if ($run->failures() !== []) : ?>
					<hr>
					<p class="mb-1"><strong><?php echo Text::_('COM_JCBINOUT_RUN_FAILURES'); ?></strong></p>
					<ul class="small mb-0">
						<?php foreach (array_slice($run->failures(), 0, 10) as $failure) : ?>
							<li>
								<code><?php echo htmlspecialchars(
									$failure['entity'] . ' ' . substr((string) $failure['value'], 0, 18),
									ENT_QUOTES, 'UTF-8'); ?></code>
								<?php echo htmlspecialchars((string) $failure['error'], ENT_QUOTES, 'UTF-8'); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ($run->failuresDropped() > 0) : ?>
						<p class="small mb-0"><?php echo Text::sprintf('COM_JCBINOUT_AND_MORE',
							$run->failuresDropped()); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<div class="mt-3">
					<button type="button" class="btn btn-primary"
						onclick="Joomla.submitform('metamodel.step');">
						<?php echo Text::_('COM_JCBINOUT_CONTINUE'); ?>
					</button>
					<button type="button" class="btn btn-outline-secondary d-none"
						id="jcbinoutPause">
						<?php echo Text::_('COM_JCBINOUT_RUN_PAUSE'); ?>
					</button>
					<span class="small text-muted ms-2" id="jcbinoutAuto"></span>
				</div>

				<p class="small text-muted mt-3 mb-0">
					<?php echo Text::_('COM_JCBINOUT_RUN_MANUAL'); ?>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<div class="card mb-3">
		<div class="card-header">
			<h2 class="card-title h5 mb-0"><?php echo Text::_('COM_JCBINOUT_IMPORT'); ?></h2>
		</div>
		<div class="card-body">
			<p><?php echo Text::_('COM_JCBINOUT_IMPORT_INTRO'); ?></p>

			<div class="mb-3">
				<label class="form-label" for="mode"><?php echo Text::_('COM_JCBINOUT_IMPORT_MODE'); ?></label>
				<select class="form-select w-auto" id="mode" name="mode">
					<option value="initialize"><?php echo Text::_('COM_JCBINOUT_MODE_INITIALIZE'); ?></option>
					<option value="reset"><?php echo Text::_('COM_JCBINOUT_MODE_RESET'); ?></option>
				</select>
				<small class="form-text"><?php echo Text::_('COM_JCBINOUT_IMPORT_MODE_HELP'); ?></small>
			</div>

			<div class="mb-3">
				<label class="form-label" for="rows"><?php echo Text::_('COM_JCBINOUT_IMPORT_ROWS'); ?></label>
				<input class="form-control w-auto" type="number" min="0" step="1"
					id="rows" name="rows" value="0">
				<small class="form-text"><?php echo Text::_('COM_JCBINOUT_IMPORT_ROWS_HELP'); ?></small>
			</div>

			<?php if ($plan === null) : ?>
				<p class="mb-0"><?php echo Text::_('COM_JCBINOUT_NO_PLAN_YET'); ?></p>
			<?php else : ?>
				<?php $counts = $plan['counts']; ?>
				<div class="alert alert-info">
					<?php echo Text::sprintf('COM_JCBINOUT_PLAN_PENDING',
						htmlspecialchars((string) $plan['mode'], ENT_QUOTES, 'UTF-8'),
						(int) ($counts['insert'] ?? 0),
						(int) ($counts['update'] ?? 0),
						(int) ($counts['skip'] ?? 0)); ?>
				</div>

				<table class="table table-sm">
					<thead>
						<tr>
							<th><?php echo Text::_('COM_JCBINOUT_ACTION'); ?></th>
							<th><?php echo Text::_('COM_JCBINOUT_ENTITY'); ?></th>
							<th><?php echo Text::_('COM_JCBINOUT_IDENTIFIER'); ?></th>
							<th class="text-end"><?php echo Text::_('COM_JCBINOUT_COLUMNS'); ?></th>
							<th><?php echo Text::_('COM_JCBINOUT_REASON'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (array_slice($plan['operations'], 0, 40) as $operation) : ?>
							<?php
								$badge = match ($operation['action']) {
									'insert' => 'bg-success',
									'update' => 'bg-warning',
									default  => 'bg-secondary',
								};
							?>
							<tr>
								<td><span class="badge <?php echo $badge; ?>">
									<?php echo htmlspecialchars($operation['action'], ENT_QUOTES, 'UTF-8'); ?>
								</span></td>
								<td><code class="small"><?php echo htmlspecialchars(
									$operation['entity'], ENT_QUOTES, 'UTF-8'); ?></code></td>
								<td><code class="small"><?php echo htmlspecialchars(
									substr((string) $operation['value'], 0, 18), ENT_QUOTES, 'UTF-8'); ?></code></td>
								<td class="text-end"><?php echo count($operation['columns']); ?></td>
								<td class="small"><?php echo htmlspecialchars(
									$operation['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if (count($plan['operations']) > 40) : ?>
					<p class="small mb-0"><?php echo Text::sprintf('COM_JCBINOUT_AND_MORE',
						count($plan['operations']) - 40); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
<?php if ($running) : ?>
	<?php
	/*
	 * Carry the run on by itself, so a blueprint that needs forty slices does
	 * not need forty clicks.
	 *
	 * The delay is long enough to call the whole thing off before the next
	 * request leaves - Pause stops this page advancing, Cancel in the toolbar
	 * abandons the run - because a loop that reloads the page is a poor place
	 * to be without a way out. Everything here is a convenience: with no
	 * JavaScript at all the Continue button does exactly the same thing, one
	 * slice at a time.
	 */
	$this->getDocument()->getWebAssetManager()->addInlineScript(
		<<<'JS'
		document.addEventListener('DOMContentLoaded', function () {
			var pause = document.getElementById('jcbinoutPause'),
				note  = document.getElementById('jcbinoutAuto'),
				left  = 3,
				timer;

			if (!pause || !note || typeof Joomla === 'undefined') {
				return;
			}

			pause.classList.remove('d-none');

			timer = window.setInterval(function () {
				left--;

				if (left > 0) {
					note.textContent = Joomla.Text._('COM_JCBINOUT_RUN_AUTO')
						.replace('%d', left);

					return;
				}

				window.clearInterval(timer);
				Joomla.submitform('metamodel.step');
			}, 1000);

			pause.addEventListener('click', function () {
				window.clearInterval(timer);
				pause.classList.add('d-none');
				note.textContent = Joomla.Text._('COM_JCBINOUT_RUN_PAUSED');
			});
		});
		JS
	);

	Text::script('COM_JCBINOUT_RUN_AUTO');
	Text::script('COM_JCBINOUT_RUN_PAUSED');
	?>
<?php endif; ?>
