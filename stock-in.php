<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$errors = [];
$values = [
    'medicine_name' => '',
    'batch_no' => '',
    'quantity' => '',
    'received_date' => date('Y-m-d'),
    'expiration_date' => '',
    'supplier' => '',
    'remarks' => '',
];

$medicines = [];
$history = [];
$loadError = '';

try {
    $pdo = get_db_connection();
    $medicines = $pdo->query('SELECT id, name, unit FROM medicines ORDER BY name')->fetchAll();
    $history = $pdo->query(
        'SELECT a.created_at, a.medicine_id, a.quantity, a.note, a.performed_by, m.name
         FROM inventory_activities a
         LEFT JOIN medicines m ON m.id = a.medicine_id
         WHERE a.activity_type = \'stock_in\'
         ORDER BY a.id DESC
         LIMIT 6'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('Stock in load error: ' . $exception->getMessage());
    $loadError = 'Unable to load stock-in data right now. Please try again later.';
}

$medicineUnits = [];
foreach ($medicines as $medicine) {
    $medicineUnits[(string)$medicine['name']] = ucfirst((string)$medicine['unit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    $selected = null;
    foreach ($medicines as $medicine) {
        if (strcasecmp((string)$medicine['name'], $values['medicine_name']) === 0) {
            $selected = $medicine;
            break;
        }
    }
    if ($values['medicine_name'] === '') {
        $errors[] = 'Please select a medicine.';
    } elseif (!$selected) {
        $errors[] = 'Please select a medicine from the list.';
    }

    if ($values['batch_no'] === '') {
        $errors[] = 'Batch number is required.';
    } elseif (mb_strlen($values['batch_no']) > 60) {
        $errors[] = 'Batch number must be 60 characters or fewer.';
    }

    if ($values['quantity'] === '' || !ctype_digit($values['quantity']) || (int)$values['quantity'] <= 0) {
        $errors[] = 'Quantity received must be a positive whole number.';
    }

    $receivedValid = DateTime::createFromFormat('Y-m-d', $values['received_date']) !== false;
    if ($values['received_date'] === '' || !$receivedValid) {
        $errors[] = 'Please enter a valid date received.';
    }

    $expirationValid = DateTime::createFromFormat('Y-m-d', $values['expiration_date']) !== false;
    if ($values['expiration_date'] === '' || !$expirationValid) {
        $errors[] = 'Expiration date is required.';
    } elseif ($receivedValid && $values['expiration_date'] <= $values['received_date']) {
        $errors[] = 'Expiration date must be later than the date received.';
    }

    if (mb_strlen($values['supplier']) > 120) {
        $errors[] = 'Supplier or source must be 120 characters or fewer.';
    }

    if (mb_strlen($values['remarks']) > 255) {
        $errors[] = 'Remarks must be 255 characters or fewer.';
    }

    if (!$errors && $selected) {
        try {
            $pdo = get_db_connection();
            $quantity = (int)$values['quantity'];

            $pdo->beginTransaction();

            $existing = $pdo->prepare(
                'SELECT id, supplier, remarks FROM medicine_batches WHERE medicine_id = :medicine_id AND batch_no = :batch_no LIMIT 1'
            );
            $existing->execute(['medicine_id' => (int)$selected['id'], 'batch_no' => $values['batch_no']]);
            $batch = $existing->fetch();

            if ($batch) {
                $update = $pdo->prepare(
                    'UPDATE medicine_batches
                     SET quantity = quantity + :quantity,
                         supplier = :supplier,
                         remarks = :remarks
                     WHERE id = :id'
                );
                $update->execute([
                    'quantity' => $quantity,
                    'supplier' => $values['supplier'] !== '' ? $values['supplier'] : (string)$batch['supplier'],
                    'remarks' => $values['remarks'] !== '' ? $values['remarks'] : (string)$batch['remarks'],
                    'id' => (int)$batch['id'],
                ]);
                $note = 'Batch ' . $values['batch_no'] . ' already existed; quantity added';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
                     VALUES (:medicine_id, :batch_no, :quantity, :received_date, :expiration_date, :supplier, :remarks)'
                );
                $insert->execute([
                    'medicine_id' => (int)$selected['id'],
                    'batch_no' => $values['batch_no'],
                    'quantity' => $quantity,
                    'received_date' => $values['received_date'],
                    'expiration_date' => $values['expiration_date'],
                    'supplier' => $values['supplier'],
                    'remarks' => $values['remarks'],
                ]);
                $note = 'Batch ' . $values['batch_no'] . ' received';
            }

            if ($values['supplier'] !== '') {
                $note .= ' from ' . $values['supplier'];
            }
            if ($values['remarks'] !== '') {
                $note .= ' - ' . $values['remarks'];
            }

            $activity = $pdo->prepare(
                'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
                 VALUES (:type, :medicine_id, :quantity, :note, :performed_by)'
            );
            $activity->execute([
                'type' => 'stock_in',
                'medicine_id' => (int)$selected['id'],
                'quantity' => $quantity,
                'note' => mb_substr($note, 0, 255),
                'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
            ]);

            create_notification(
                $pdo,
                $quantity . ' ' . $selected['unit'] . '(s) of ' . $selected['name']
                    . ' (batch ' . $values['batch_no'] . ') were added to stock.'
            );

            $pdo->commit();

            $_SESSION['flash_success'] = number_format($quantity) . ' ' . ucfirst((string)$selected['unit']) . '(s) of "' . $selected['name'] . '" (batch ' . $values['batch_no'] . ') saved to stock.';
            header('Location: stock-in.php');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock in error: ' . $exception->getMessage());
            $errors[] = 'The stock-in record could not be saved right now. Please try again.';
        }
    }
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$identity = session_identity();
render_page_start('Stock In', 'stock-in', $identity['full_name'], unread_notifications_count(), 'Record medicines received by the school clinic.');
?>
    <main class="content">
      <?php if ($flash !== ''): ?>
        <div class="alert success" role="status"><?php echo e($flash); ?></div>
      <?php endif; ?>

      <?php if ($loadError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($loadError); ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert" role="alert">
          <ul class="alert-list">
            <?php foreach ($errors as $error): ?>
              <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="panel form-panel">
        <form method="POST" id="stockInForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

          <h2 class="form-title">Stock-In Details</h2>
          <div class="form-grid">
            <div class="field">
              <label for="medicine_name">Select Medicine <span class="req">*</span></label>
              <input type="text" id="medicine_name" name="medicine_name" list="medicineOptions" maxlength="150" placeholder="Type to search medicine..." value="<?php echo e($values['medicine_name']); ?>" autocomplete="off" required>
              <datalist id="medicineOptions">
                <?php foreach ($medicines as $medicine): ?>
                  <option value="<?php echo e($medicine['name']); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="field">
              <label for="unitDisplay">Unit of Measurement</label>
              <input type="text" id="unitDisplay" value="" readonly tabindex="-1" placeholder="Auto-filled from medicine">
              <span class="help-inline">Automatically retrieved from the selected medicine.</span>
            </div>

            <div class="field">
              <label for="batch_no">Batch Number <span class="req">*</span></label>
              <input type="text" id="batch_no" name="batch_no" maxlength="60" placeholder="e.g. BT-2026-001" value="<?php echo e($values['batch_no']); ?>" required>
            </div>

            <div class="field">
              <label for="quantity">Quantity Received <span class="req">*</span></label>
              <input type="number" id="quantity" name="quantity" min="1" step="1" placeholder="e.g. 50" value="<?php echo e($values['quantity']); ?>" required>
            </div>

            <div class="field">
              <label for="received_date">Date Received</label>
              <input type="date" id="received_date" name="received_date" value="<?php echo e($values['received_date']); ?>">
            </div>

            <div class="field">
              <label for="expiration_date">Expiration Date <span class="req">*</span></label>
              <input type="date" id="expiration_date" name="expiration_date" value="<?php echo e($values['expiration_date']); ?>" required>
            </div>

            <div class="field">
              <label for="supplier">Supplier or Source <span class="muted small">(optional)</span></label>
              <input type="text" id="supplier" name="supplier" maxlength="120" placeholder="e.g. City Health Office" value="<?php echo e($values['supplier']); ?>">
            </div>

            <div class="field">
              <label for="remarks">Remarks <span class="muted small">(optional)</span></label>
              <input type="text" id="remarks" name="remarks" maxlength="255" placeholder="e.g. Donated stock" value="<?php echo e($values['remarks']); ?>">
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn primary"><?php echo icon('stock-in'); ?> Save Stock</button>
            <button type="reset" class="btn">Clear Form</button>
            <a class="btn" href="medicine-inventory.php">Cancel</a>
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Recent Stock-In History</h2>
          <span class="badge teal"><?php echo number_format(count($history)); ?> recent</span>
        </div>
        <?php if (!$history): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('stock-in'); ?></span>
            <h3>No stock-in records yet</h3>
            <p>Recorded stock receipts will appear here.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Date</th><th>Medicine</th><th>Quantity</th><th>Details</th><th>Recorded By</th></tr>
              </thead>
              <tbody>
                <?php foreach ($history as $entry): ?>
                  <tr>
                    <td><?php echo date('M j, Y g:i A', strtotime((string)$entry['created_at'])); ?></td>
                    <td class="strong"><?php if ($entry['medicine_id'] !== null && $entry['name'] !== null): ?><a class="table-link" href="medicine-view.php?id=<?php echo (int)$entry['medicine_id']; ?>#batches"><?php echo e($entry['name']); ?></a><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></td>
                    <td class="strong"><?php echo number_format((int)$entry['quantity']); ?></td>
                    <td><?php echo e((string)$entry['note']); ?></td>
                    <td><?php echo e((string)$entry['performed_by']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <script>
      (function () {
        var units = <?php echo json_encode($medicineUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var nameInput = document.getElementById('medicine_name');
        var unitInput = document.getElementById('unitDisplay');
        var form = document.getElementById('stockInForm');
        if (!nameInput || !unitInput) return;

        function syncUnit() {
          var key = nameInput.value.trim();
          var match = null;
          Object.keys(units).forEach(function (name) {
            if (name.toLowerCase() === key.toLowerCase()) match = name;
          });
          unitInput.value = match ? units[match] : '';
        }

        nameInput.addEventListener('input', syncUnit);
        nameInput.addEventListener('change', syncUnit);
        if (form) form.addEventListener('reset', function () { setTimeout(syncUnit, 0); });
        syncUnit();
      })();
    </script>
<?php render_page_end(); ?>
